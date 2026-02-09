# GTFS Transit

> Public transport data from GTFS Sverige 2 — official timetable from Samtrafiken.

## Status: :green_circle: Implemented

GTFS transit data ingestion replaces OSM-sourced transit stops with authoritative timetable data.

## Data Source

| Source | Region | GTFS Feed |
|---|---|---|
| Trafiklab GTFS Sverige 2 | All Sweden | National consolidated feed |

API key required: register at https://www.trafiklab.se/api/gtfs-sverige-2/

Set `TRAFIKLAB_GTFS_KEY` in `.env`.

## Command

```bash
php artisan ingest:gtfs
php artisan ingest:gtfs --target-date=20260310
php artisan ingest:gtfs --file=/path/to/sweden.zip
php artisan ingest:gtfs --skip-download
```

## Pipeline

1. Download GTFS Sverige 2 zip (~40MB)
2. Extract to `storage/app/data/raw/gtfs/`
3. Clear old OSM transit POIs and previous GTFS data
4. Import `stops.txt` into `transit_stops` table (~47K stops)
5. Compute frequencies via Python/pandas (`scripts/compute_gtfs_frequencies.py`)
6. Import frequency CSV into `transit_stop_frequencies` table
7. Backfill `stop_type`, `weekly_departures`, `routes_count` on `transit_stops`
8. Assign DeSO codes via PostGIS spatial join
9. Insert qualifying high-value stops into `pois` table

## Tables

- `transit_stops` — authoritative transit stop data (source, location, frequency metrics)
- `transit_stop_frequencies` — per-stop departure counts by mode and time bucket

## Indicators

| Indicator | Unit | Direction | Source |
|---|---|---|---|
| `transit_stop_density` | per 1,000 residents | positive | Area-level (via POI aggregation) |
| `prox_transit` | proximity score 0-100 | positive | Per-coordinate (ProximityScoreService) |

## Proximity Scoring

`ProximityScoreService::scoreTransit()` queries `transit_stops` with:
- Mode weighting: rail/subway (1.5x) > tram (1.2x) > bus (1.0x)
- Frequency bonus: log-scaled from `weekly_departures`
- Safety-modulated distance decay
- Urbanity-aware radii (800m urban, 1200m semi-urban, 2500m rural)

## Related

- [Data Sources Overview](/data-sources/)
- [POI System](/indicators/poi)
