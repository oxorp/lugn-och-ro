#!/usr/bin/env python3
"""
Pre-process GTFS stop_times.txt into per-stop frequency counts.
Called from Laravel: php artisan ingest:gtfs

Input: extracted GTFS directory
Output: CSV with stop frequencies ready for bulk import

Usage:
    python3 compute_gtfs_frequencies.py <gtfs_dir> <output_csv> [target_date]

    target_date format: YYYYMMDD (e.g., 20260310)
    If omitted, picks a representative Tuesday ~4 weeks from today.
"""
import datetime
import sys
from pathlib import Path

import pandas as pd


def main(gtfs_dir: str, output_path: str, target_date: str = None):
    gtfs = Path(gtfs_dir)

    # 1. Load small reference files fully
    calendar = pd.read_csv(gtfs / 'calendar.txt', dtype=str)
    trips = pd.read_csv(
        gtfs / 'trips.txt', dtype=str,
        usecols=['trip_id', 'route_id', 'service_id']
    )
    routes = pd.read_csv(
        gtfs / 'routes.txt', dtype=str,
        usecols=['route_id', 'route_type']
    )
    routes['route_type'] = routes['route_type'].astype(int)

    # Load calendar_dates if it exists (optional in GTFS)
    calendar_dates_path = gtfs / 'calendar_dates.txt'
    if calendar_dates_path.exists():
        calendar_dates = pd.read_csv(calendar_dates_path, dtype=str)
    else:
        calendar_dates = pd.DataFrame(
            columns=['service_id', 'date', 'exception_type']
        )

    # 2. Determine target date
    if target_date is None:
        today = datetime.date.today()
        days_until_tuesday = (1 - today.weekday()) % 7
        if days_until_tuesday == 0:
            days_until_tuesday = 7
        target = today + datetime.timedelta(days=days_until_tuesday + 28)
        target_date = target.strftime('%Y%m%d')

    print(f"Target date: {target_date}")

    # 3. Find active services for target date
    dow = pd.Timestamp(target_date).day_name().lower()

    # Services active on this day of week within their date range
    active_services = set()
    for _, row in calendar.iterrows():
        if row.get(dow) == '1':
            start = row.get('start_date', '19700101')
            end = row.get('end_date', '20991231')
            if start <= target_date <= end:
                active_services.add(row['service_id'])

    # Apply calendar_dates exceptions
    additions = set(
        calendar_dates[
            (calendar_dates['date'] == target_date) &
            (calendar_dates['exception_type'] == '1')
        ]['service_id']
    )
    removals = set(
        calendar_dates[
            (calendar_dates['date'] == target_date) &
            (calendar_dates['exception_type'] == '2')
        ]['service_id']
    )
    active_services = (active_services | additions) - removals

    if not active_services:
        print("ERROR: No active services found for target date")
        sys.exit(1)

    # 4. Filter trips to active services, merge with route type
    active_trips = trips[trips['service_id'].isin(active_services)].merge(
        routes, on='route_id', how='left'
    )
    active_trip_ids = set(active_trips['trip_id'])
    trip_route_type = dict(zip(active_trips['trip_id'], active_trips['route_type']))
    trip_route_id = dict(zip(active_trips['trip_id'], active_trips['route_id']))

    print(f"Active services: {len(active_services)}, Active trips: {len(active_trip_ids)}")

    # 5. Stream stop_times.txt in chunks
    chunk_results = []
    route_tracking = []

    for chunk in pd.read_csv(
        gtfs / 'stop_times.txt',
        dtype=str,
        usecols=['trip_id', 'departure_time', 'stop_id'],
        chunksize=500_000,
    ):
        # Filter to active trips
        chunk = chunk[chunk['trip_id'].isin(active_trip_ids)]

        if chunk.empty:
            continue

        # Map route type and route_id
        chunk['route_type'] = chunk['trip_id'].map(trip_route_type)
        chunk['route_id'] = chunk['trip_id'].map(trip_route_id)

        # Parse departure hour (handle >24:00:00 overnight services)
        chunk['hour'] = (
            chunk['departure_time']
            .str.split(':').str[0]
            .astype(int) % 24
        )

        # Classify mode
        chunk['mode'] = chunk['route_type'].apply(classify_mode)

        # Classify time bucket
        chunk['bucket'] = chunk['hour'].apply(classify_bucket)

        # Keep only 06-22 range
        chunk = chunk[chunk['bucket'] != 'outside']

        # Departure counts
        chunk_results.append(
            chunk.groupby(['stop_id', 'mode', 'bucket'])
            .size()
            .reset_index(name='departures')
        )

        # Track distinct routes per stop+mode
        route_tracking.append(
            chunk.groupby(['stop_id', 'mode'])['route_id']
            .nunique()
            .reset_index(name='distinct_routes')
        )

    # 6. Combine all chunks
    if not chunk_results:
        print("ERROR: No departures found for target date")
        sys.exit(1)

    result = (
        pd.concat(chunk_results)
        .groupby(['stop_id', 'mode', 'bucket'])['departures']
        .sum()
        .reset_index()
    )

    # Combine distinct route counts
    route_counts = (
        pd.concat(route_tracking)
        .groupby(['stop_id', 'mode'])['distinct_routes']
        .max()  # max across chunks (nunique per chunk is a lower bound)
        .reset_index()
    )

    # 7. Pivot to wide format (one row per stop+mode)
    pivot = result.pivot_table(
        index=['stop_id', 'mode'],
        columns='bucket',
        values='departures',
        fill_value=0,
    ).reset_index()

    # Ensure all bucket columns exist
    for col in ['departures_06_09', 'departures_09_15',
                'departures_15_18', 'departures_18_22']:
        if col not in pivot.columns:
            pivot[col] = 0

    pivot.columns.name = None
    pivot['departures_06_20_total'] = (
        pivot['departures_06_09'] + pivot['departures_09_15'] +
        pivot['departures_15_18'] + pivot['departures_18_22']
    )

    # Merge distinct route counts
    pivot = pivot.merge(route_counts, on=['stop_id', 'mode'], how='left')
    pivot['distinct_routes'] = pivot['distinct_routes'].fillna(0).astype(int)

    pivot['day_type'] = 'weekday'
    pivot['feed_version'] = target_date[:4] + '-' + target_date[4:6]

    # Reorder columns for clean CSV
    output_cols = [
        'stop_id', 'mode',
        'departures_06_09', 'departures_09_15',
        'departures_15_18', 'departures_18_22',
        'departures_06_20_total', 'distinct_routes',
        'day_type', 'feed_version',
    ]
    pivot = pivot[output_cols]

    # 8. Write output
    pivot.to_csv(output_path, index=False)
    print(f"Written {len(pivot)} frequency records to {output_path}")
    print(f"Unique stops: {pivot['stop_id'].nunique()}")
    print(f"By mode: {pivot.groupby('mode')['departures_06_20_total'].sum().to_dict()}")


def classify_mode(route_type):
    """Classify GTFS route_type into simplified mode categories."""
    if pd.isna(route_type):
        return 'bus'
    rt = int(route_type)
    # Extended GTFS route types (Samtrafiken uses these)
    if 100 <= rt < 200:
        return 'rail'
    if 400 <= rt < 500:
        return 'subway'
    if rt == 0 or (900 <= rt < 1000):
        return 'tram'
    if rt == 4 or (1000 <= rt < 1100):
        return 'ferry'
    if rt >= 1500:
        return 'on_demand'
    # Standard GTFS route types
    if rt == 2:
        return 'rail'
    if rt == 1:
        return 'subway'
    return 'bus'


def classify_bucket(hour):
    """Classify hour into departure time bucket."""
    if 6 <= hour < 9:
        return 'departures_06_09'
    if 9 <= hour < 15:
        return 'departures_09_15'
    if 15 <= hour < 18:
        return 'departures_15_18'
    if 18 <= hour < 22:
        return 'departures_18_22'
    return 'outside'


if __name__ == '__main__':
    if len(sys.argv) < 3:
        print("Usage: compute_gtfs_frequencies.py <gtfs_dir> <output_csv> [target_date]")
        sys.exit(1)

    gtfs_dir = sys.argv[1]
    output_path = sys.argv[2]
    target_date = sys.argv[3] if len(sys.argv) > 3 else None
    main(gtfs_dir, output_path, target_date)
