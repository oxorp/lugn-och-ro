# AMENDMENT: GTFS Transit Task — OSM Migration, Deduplication & Performance

## Applies To

`task-gtfs-transit.md` (Public Transport Accessibility — GTFS Ingestion & Transit Scoring)

This amendment addresses the fact that the proximity scoring task already ingests transit stops from OpenStreetMap. The GTFS task was written assuming a blank slate. This document specifies how GTFS data replaces, coexists with, and supersedes OSM transit data.

---

## The Situation

### What We Already Have (from proximity task)

The proximity scoring task ingests transit stops via OSM Overpass:
```
node["public_transport"="stop_position"](area.sweden);
node["highway"="bus_stop"](area.sweden);
node["railway"="station"](area.sweden);
node["railway"="tram_stop"](area.sweden);
node["railway"="halt"](area.sweden);
```

These go into two places:
1. **`transit_stops` table** — dedicated table with lat/lng, name, basic type
2. **`pois` table** — transit stops also inserted as POIs with `category = 'transit_stop'`

OSM gives us: location, name, basic type tag. ~30,000-60,000 stops.

OSM does NOT give us: departure frequency, route information, operating hours, mode quality, operator, whether the stop is actually active.

### What GTFS Gives Us

GTFS Sverige 2 gives us: everything OSM has, plus departure counts per time window, route types with extended mode classification, operator info, service patterns, parent-station grouping. ~47,000 stops.

**GTFS is the authority on transit.** OSM is community-maintained and varies wildly in completeness — some stops have detailed metadata, others are just a dot on the map. GTFS is the official timetable from Samtrafiken, updated daily, covering every licensed operator in Sweden.

---

## Decision: GTFS Replaces OSM for Transit

### What happens to OSM transit data

**Discard it.** When GTFS ingestion runs:

1. Delete all rows from `pois` where `source = 'osm'` AND `category IN ('transit_stop', 'bus_stop', 'tram_stop', 'rail_station', 'subway_station')` — any transit-related POI sourced from OSM
2. Delete all rows from `transit_stops` where `source = 'osm'` (if the source column exists) or truncate if the table was exclusively OSM-sourced
3. Import GTFS stops into `transit_stops` (the authoritative table)
4. Insert qualifying GTFS stops into `pois` (only high-value stops, per the display tier logic in the GTFS task)

### Why not keep both and deduplicate?

Deduplication between OSM and GTFS is surprisingly hard and not worth the effort:

- **ID mismatch:** OSM uses `node:12345678`, GTFS uses `740012345`. No shared key.
- **Name mismatch:** OSM might say "Sundbybergs station" while GTFS says "Sundbyberg station" or "Sundbyberg". Fuzzy matching at scale = bugs.
- **Coordinate drift:** OSM has the stop at 59.36128, 17.97189. GTFS has it at 59.36132, 17.97185. Close but not identical. Matching on "within 50m" catches false positives in dense urban areas where stops are 30m apart.
- **Granularity mismatch:** GTFS has parent stations with child platforms. OSM sometimes has the station as one node, sometimes individual platforms, sometimes both. Merging this is a headache with zero value.
- **The merge adds nothing:** Every useful field GTFS has, OSM either also has (location, name) or doesn't have at all (frequency, routes). There's no OSM-only transit data worth preserving.

**The only exception:** If OSM has transit stops that GTFS somehow misses (informal stops, very recent additions not yet in the timetable), those are edge cases not worth building infrastructure for.

### Migration command

Add to the GTFS ingestion command:

```php
// Step 0: Clear OSM transit data before importing GTFS
$this->info('Clearing OSM-sourced transit data...');

$deletedPois = Poi::where('source', 'osm')
    ->whereIn('category', ['transit_stop', 'bus_stop', 'tram_stop', 'rail_station', 'subway_station', 'transit'])
    ->delete();
$this->info("  Deleted {$deletedPois} OSM transit POIs");

$deletedStops = TransitStop::where('source', 'osm')->delete();
// Or if no source column: TransitStop::truncate();
$this->info("  Deleted {$deletedStops} OSM transit stops");

// Step 1-5: GTFS import as specified in the main task
```

### The `source` column

Both `transit_stops` and `pois` should have a `source` column if they don't already. This makes the "delete old source, import new source" pattern clean:

```php
// If not already present
Schema::table('transit_stops', function (Blueprint $table) {
    $table->string('source', 20)->default('osm')->index();  // 'osm' or 'gtfs'
});
```

After GTFS import, all transit_stops have `source = 'gtfs'`. Future re-imports do `DELETE WHERE source = 'gtfs'` then reimport — idempotent.

### Update proximity scoring

The `ProximityScoreService::scoreTransit()` method queries `transit_stops` directly. No code change needed — it doesn't care whether the data came from OSM or GTFS. The table structure stays the same, just with better data in it.

The one code change: after GTFS import, `transit_stops` has `weekly_departures` and `routes_count` populated (these were likely NULL with OSM data). The proximity scorer can now use frequency weighting:

```php
private function scoreTransit(float $lat, float $lng, float $safetyScore = 1.0): ProximityFactor
{
    $stops = DB::select("
        SELECT ts.*, 
               ST_Distance(ts.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m
        FROM transit_stops ts
        WHERE ST_DWithin(ts.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 1000)
        ORDER BY distance_m
        LIMIT 10
    ", [$lng, $lat, $lng, $lat]);

    if (empty($stops)) {
        return new ProximityFactor(slug: 'transit', score: 0, details: [...]);
    }

    // Best stop: highest mode_weight × frequency, closest distance
    $bestScore = 0;
    $bestStop = null;

    foreach ($stops as $stop) {
        $modeWeight = match($stop->stop_type ?? 'bus') {
            'rail', 'subway' => 1.5,
            'tram' => 1.2,
            default => 1.0,
        };

        // Frequency bonus: a stop with 200 departures/day is much better than 5
        $freqBonus = $stop->weekly_departures
            ? min(1.5, 0.5 + log10(max(1, $stop->weekly_departures / 7)) / 3)
            : 1.0;  // Default if no frequency data (backward compat with OSM)

        $settings = $this->getCategorySettings()->get('transit_stop');
        $decay = $this->decayWithSafety(
            $stop->distance_m,
            $settings->max_distance_m ?? 1000,
            $safetyScore,
            $settings->safety_sensitivity ?? 0.5,
        );

        $score = $decay * $modeWeight * $freqBonus * 100;

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestStop = $stop;
        }
    }

    return new ProximityFactor(
        slug: 'transit',
        score: min(100, round($bestScore)),
        details: [
            'nearest_stop' => $bestStop->name,
            'nearest_distance_m' => round($bestStop->distance_m),
            'mode' => $bestStop->stop_type ?? 'bus',
            'weekly_departures' => $bestStop->weekly_departures,
        ],
    );
}
```

---

## POI Table Strategy

### Which GTFS stops go into the `pois` table?

The GTFS task already specifies this (Step 7), but to be explicit about the POI integration:

**DO insert into `pois`:**
- Major rail stations (intercity, >20 departures/day) → `category: 'rail_station'`, display tier 1
- Commuter rail + subway stations → `category: 'rail_station'`, display tier 2
- Tram stops → `category: 'tram_stop'`, display tier 3
- High-frequency bus stops (≥56 departures/day i.e. ≥4/hour over 14 hours) → `category: 'bus_stop_hf'`, display tier 4

**DO NOT insert into `pois`:**
- Regular bus stops (<4 departures/hour) — there are ~35,000 of these, they'd overwhelm the map
- Individual platforms (location_type = 0 with a parent_station) — use parent station only
- Inactive/seasonal stops

**POI metadata for transit:**

```php
Poi::updateOrCreate(
    ['source' => 'gtfs', 'source_id' => $station->gtfs_stop_id],
    [
        'category' => $this->poiCategory($station, $frequency),
        'subcategory' => $modeCategory,  // 'rail', 'subway', 'tram', 'bus'
        'name' => $station->name,
        'lat' => $station->lat,
        'lng' => $station->lng,
        'signal' => 'positive',
        'metadata' => [
            'departures_weekday' => $frequency->departures_06_20_total,
            'departures_peak_hour' => max($frequency->departures_06_09 / 3, $frequency->departures_15_18 / 3),
            'distinct_routes' => $frequency->distinct_routes,
            'mode' => $modeCategory,
            'operators' => $operators,
        ],
        'source' => 'gtfs',
        'source_id' => $station->gtfs_stop_id,
    ]
);
```

### What about non-transit POIs from OSM?

Parks, grocery stores, cafés, gyms, negative POIs — all of these remain sourced from OSM. Only transit POIs get replaced by GTFS. The `pois` table will have mixed sources:

```
source = 'osm'   → parks, grocery, cafes, gyms, pawnbrokers, gambling, etc.
source = 'gtfs'  → rail stations, subway, tram stops, high-frequency bus stops
```

The `source` column makes this clean. OSM ingestion command skips transit categories. GTFS ingestion command only touches transit categories.

```php
// In the OSM ingestion command, add an exclusion:
// Skip transit categories — these come from GTFS
$skipCategories = ['transit_stop', 'bus_stop', 'tram_stop', 'rail_station', 'subway_station'];
```

---

## Processing Speed Optimization

### The Problem

`stop_times.txt` for all of Sweden is 5-10 million rows. Parsing this in PHP with `fgetcsv` works but is slow. The GTFS task says "stream, don't load into memory" — correct, but we can do better.

### Strategy: Pre-process in Python, Import in Laravel

The frequency computation (counting departures per stop per mode per time window) is a classic data-crunching job. Python with pandas does this 10-50× faster than PHP CSV parsing.

```
Flow:
1. Laravel downloads the GTFS zip
2. Laravel calls Python script to compute frequencies
3. Python outputs a single CSV: stop_id, mode, day_type, departures_per_bucket
4. Laravel imports the CSV into transit_stop_frequencies (bulk insert)
5. Laravel imports stops.txt directly (simple, 47k rows)
6. Laravel does spatial joins and indicator aggregation
```

### Python Frequency Script

```python
#!/usr/bin/env python3
"""
Pre-process GTFS stop_times.txt into per-stop frequency counts.
Called from Laravel: php artisan ingest:gtfs

Input: extracted GTFS directory
Output: CSV with stop frequencies ready for bulk import
"""
import pandas as pd
import sys
from pathlib import Path

def main(gtfs_dir: str, output_path: str, target_date: str = None):
    gtfs = Path(gtfs_dir)
    
    # 1. Load small files fully (these are tiny)
    calendar = pd.read_csv(gtfs / 'calendar.txt', dtype=str)
    calendar_dates = pd.read_csv(gtfs / 'calendar_dates.txt', dtype=str)
    trips = pd.read_csv(gtfs / 'trips.txt', dtype=str, usecols=['trip_id', 'route_id', 'service_id'])
    routes = pd.read_csv(gtfs / 'routes.txt', dtype=str, usecols=['route_id', 'route_type'])
    routes['route_type'] = routes['route_type'].astype(int)
    
    # 2. Find active services for target date
    # (pick a representative Tuesday 4 weeks from now if not specified)
    if target_date is None:
        import datetime
        today = datetime.date.today()
        # Find next Tuesday that's at least 4 weeks out
        days_until_tuesday = (1 - today.weekday()) % 7
        if days_until_tuesday == 0:
            days_until_tuesday = 7
        target = today + datetime.timedelta(days=days_until_tuesday + 28)
        target_date = target.strftime('%Y%m%d')
    
    dow = pd.Timestamp(target_date).day_name().lower()
    active_services = set(
        calendar[calendar[dow] == '1']['service_id']
    )
    # Add exceptions
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
    
    # 3. Filter trips to active services, merge with route type
    active_trips = trips[trips['service_id'].isin(active_services)].merge(
        routes, on='route_id', how='left'
    )
    active_trip_ids = set(active_trips['trip_id'])
    trip_route_type = dict(zip(active_trips['trip_id'], active_trips['route_type']))
    
    print(f"Active services: {len(active_services)}, Active trips: {len(active_trip_ids)}")
    
    # 4. Stream stop_times.txt in chunks — this is the big file
    # Only load columns we need, filter immediately
    chunk_results = []
    
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
        
        # Map route type
        chunk['route_type'] = chunk['trip_id'].map(trip_route_type)
        
        # Parse departure hour (handle >24:00:00 overnight services)
        chunk['hour'] = chunk['departure_time'].str.split(':').str[0].astype(int) % 24
        
        # Classify mode
        chunk['mode'] = chunk['route_type'].astype(float).apply(classify_mode)
        
        # Classify time bucket
        chunk['bucket'] = chunk['hour'].apply(classify_bucket)
        
        # Keep only 06-22 range
        chunk = chunk[chunk['bucket'] != 'outside']
        
        chunk_results.append(
            chunk.groupby(['stop_id', 'mode', 'bucket']).size().reset_index(name='departures')
        )
    
    # 5. Combine all chunks
    if not chunk_results:
        print("ERROR: No departures found for target date")
        sys.exit(1)
    
    result = pd.concat(chunk_results).groupby(['stop_id', 'mode', 'bucket'])['departures'].sum().reset_index()
    
    # 6. Pivot to wide format (one row per stop+mode)
    pivot = result.pivot_table(
        index=['stop_id', 'mode'],
        columns='bucket',
        values='departures',
        fill_value=0,
    ).reset_index()
    
    # Rename columns to match DB schema
    pivot.columns = ['stop_id', 'mode', 'departures_06_09', 'departures_09_15', 'departures_15_18', 'departures_18_22']
    pivot['departures_06_20_total'] = (
        pivot['departures_06_09'] + pivot['departures_09_15'] + 
        pivot['departures_15_18'] + pivot['departures_18_22']
    )
    
    # Count distinct routes per stop+mode
    # (we'd need to track this separately — simplified here)
    pivot['day_type'] = 'weekday'
    pivot['feed_version'] = target_date[:7]  # e.g., '2026-02'
    
    # 7. Write output
    pivot.to_csv(output_path, index=False)
    print(f"Written {len(pivot)} frequency records to {output_path}")
    print(f"Unique stops: {pivot['stop_id'].nunique()}")
    print(f"By mode: {pivot.groupby('mode')['departures_06_20_total'].sum().to_dict()}")


def classify_mode(route_type):
    if pd.isna(route_type):
        return 'bus'
    rt = int(route_type)
    if 100 <= rt < 200: return 'rail'
    if 400 <= rt < 500: return 'subway'
    if rt == 0 or 900 <= rt < 1000: return 'tram'
    if rt == 4 or 1000 <= rt < 1100: return 'ferry'
    if rt >= 1500: return 'on_demand'
    return 'bus'


def classify_bucket(hour):
    if 6 <= hour < 9: return 'departures_06_09'
    if 9 <= hour < 15: return 'departures_09_15'
    if 15 <= hour < 18: return 'departures_15_18'
    if 18 <= hour < 22: return 'departures_18_22'
    return 'outside'


if __name__ == '__main__':
    gtfs_dir = sys.argv[1]
    output_path = sys.argv[2]
    target_date = sys.argv[3] if len(sys.argv) > 3 else None
    main(gtfs_dir, output_path, target_date)
```

### Performance Expectations

| Step | Method | Time |
|---|---|---|
| Download GTFS zip (40MB) | HTTP | ~5s |
| Extract zip | PHP ZipArchive | ~3s |
| Python frequency computation | pandas chunked | **~30-60s** |
| Import stops.txt (47k rows) | Laravel bulk insert | ~5s |
| Import frequency CSV | Laravel bulk insert | ~10s |
| Spatial join (stops → DeSO) | PostGIS ST_Contains | ~15s |
| Clear old OSM transit data | DELETE WHERE source | ~2s |
| Insert POIs (qualifying stops) | Laravel bulk insert | ~3s |
| Aggregate to DeSO indicators | SQL + PHP | ~30s |
| **Total** | | **~2-3 minutes** |

Compare to pure PHP parsing of stop_times.txt: 15-30 minutes. Python with pandas chunked reading is the move.

### Laravel Command Structure

```php
class IngestGtfs extends Command
{
    protected $signature = 'ingest:gtfs {--target-date=} {--skip-download}';

    public function handle()
    {
        $log = IngestionLog::start('gtfs', 'ingest:gtfs');

        try {
            // Step 1: Download
            if (!$this->option('skip-download')) {
                $this->downloadGtfsFeed();
            }

            // Step 2: Extract
            $extractDir = $this->extractZip();

            // Step 3: Clear old transit data
            $this->clearOldTransitData();

            // Step 4: Import stops (PHP — simple CSV, 47k rows)
            $stopCount = $this->importStops($extractDir);

            // Step 5: Compute frequencies (Python — heavy lifting)
            $freqCsv = $this->computeFrequencies($extractDir);

            // Step 6: Import frequencies (PHP — bulk insert from CSV)
            $freqCount = $this->importFrequencies($freqCsv);

            // Step 7: Spatial join (PostGIS)
            $this->assignDesoCodes();

            // Step 8: Insert qualifying stops as POIs
            $poiCount = $this->insertTransitPois();

            // Step 9: Aggregate to DeSO-level indicators
            $this->call('aggregate:transit-indicators');

            $log->complete($stopCount, $poiCount);

        } catch (\Exception $e) {
            $log->fail($e->getMessage());
            throw $e;
        }
    }

    private function clearOldTransitData(): void
    {
        $this->info('Clearing old transit data...');

        // Remove OSM-sourced transit POIs
        $deletedPois = DB::table('pois')
            ->where('source', 'osm')
            ->where(function ($q) {
                $q->where('category', 'like', 'transit%')
                  ->orWhere('category', 'like', 'bus%')
                  ->orWhere('category', 'like', 'rail%')
                  ->orWhere('category', 'like', 'tram%')
                  ->orWhere('category', 'like', 'subway%');
            })
            ->delete();

        // Remove old GTFS data (for re-import idempotency)
        $deletedStops = DB::table('transit_stops')->where('source', 'gtfs')->delete();
        $deletedFreqs = DB::table('transit_stop_frequencies')->delete();

        // Also remove old GTFS POIs
        $deletedGtfsPois = DB::table('pois')->where('source', 'gtfs')->delete();

        $this->info("  Cleared: {$deletedPois} OSM POIs, {$deletedStops} stops, {$deletedFreqs} frequencies, {$deletedGtfsPois} GTFS POIs");
    }

    private function computeFrequencies(string $extractDir): string
    {
        $outputCsv = storage_path('app/data/processed/gtfs_frequencies.csv');
        $targetDate = $this->option('target-date');

        $cmd = [
            'python3',
            base_path('scripts/compute_gtfs_frequencies.py'),
            $extractDir,
            $outputCsv,
        ];
        if ($targetDate) $cmd[] = $targetDate;

        $this->info('Computing frequencies via Python...');
        $process = Process::run($cmd);

        if (!$process->successful()) {
            throw new \RuntimeException("Python frequency computation failed: " . $process->errorOutput());
        }

        $this->info($process->output());
        return $outputCsv;
    }

    private function importStops(string $extractDir): int
    {
        $stopsFile = $extractDir . '/stops.txt';
        $handle = fopen($stopsFile, 'r');
        $header = fgetcsv($handle);
        $colMap = array_flip($header);

        $batch = [];
        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $locationType = (int) ($row[$colMap['location_type'] ?? -1] ?? 0);

            // Only import stops (0) and stations (1)
            if ($locationType > 1) continue;

            $lat = (float) $row[$colMap['stop_lat']];
            $lng = (float) $row[$colMap['stop_lon']];

            // Skip if outside Sweden bounds
            if ($lat < 55 || $lat > 69 || $lng < 11 || $lng > 25) continue;

            $batch[] = [
                'gtfs_stop_id' => $row[$colMap['stop_id']],
                'name' => $row[$colMap['stop_name']],
                'lat' => $lat,
                'lng' => $lng,
                'parent_station' => $row[$colMap['parent_station'] ?? -1] ?? null,
                'location_type' => $locationType,
                'source' => 'gtfs',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($batch) >= 1000) {
                DB::table('transit_stops')->insert($batch);
                $count += count($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            DB::table('transit_stops')->insert($batch);
            $count += count($batch);
        }

        fclose($handle);

        // Set PostGIS geometry column
        DB::statement("
            UPDATE transit_stops 
            SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326)
            WHERE source = 'gtfs' AND geom IS NULL
        ");

        $this->info("  Imported {$count} stops");
        return $count;
    }

    private function importFrequencies(string $csvPath): int
    {
        // Bulk import from the Python-generated CSV
        // Use PostgreSQL COPY for maximum speed
        $count = DB::getDriverName() === 'pgsql'
            ? $this->copyFromCsv($csvPath)
            : $this->insertFromCsv($csvPath);

        $this->info("  Imported {$count} frequency records");
        return $count;
    }

    private function copyFromCsv(string $csvPath): int
    {
        // PostgreSQL COPY is the fastest way to bulk-load CSV
        $tempTable = 'transit_freq_import_' . time();

        DB::statement("
            CREATE TEMP TABLE {$tempTable} (
                stop_id VARCHAR(30),
                mode VARCHAR(20),
                departures_06_09 INTEGER,
                departures_09_15 INTEGER,
                departures_15_18 INTEGER,
                departures_18_22 INTEGER,
                departures_06_20_total INTEGER,
                day_type VARCHAR(10),
                feed_version VARCHAR(20)
            )
        ");

        DB::statement("
            COPY {$tempTable} FROM '{$csvPath}' 
            WITH (FORMAT csv, HEADER true)
        ");

        $count = DB::table($tempTable)->count();

        DB::statement("
            INSERT INTO transit_stop_frequencies 
                (gtfs_stop_id, mode_category, departures_06_09, departures_09_15, 
                 departures_15_18, departures_18_22, departures_06_20_total, 
                 day_type, feed_version, created_at, updated_at)
            SELECT stop_id, mode, departures_06_09, departures_09_15,
                   departures_15_18, departures_18_22, departures_06_20_total,
                   day_type, feed_version, NOW(), NOW()
            FROM {$tempTable}
        ");

        DB::statement("DROP TABLE {$tempTable}");

        return $count;
    }
}
```

---

## Update to `transit_stops` Schema

Add `source` column and `weekly_departures`/`routes_count` for backward compatibility with proximity scoring:

```php
// Migration: add columns if they don't exist
Schema::table('transit_stops', function (Blueprint $table) {
    if (!Schema::hasColumn('transit_stops', 'source')) {
        $table->string('source', 20)->default('osm')->index();
    }
    if (!Schema::hasColumn('transit_stops', 'stop_type')) {
        $table->string('stop_type', 20)->nullable(); // 'rail', 'subway', 'tram', 'bus', 'ferry'
    }
    if (!Schema::hasColumn('transit_stops', 'weekly_departures')) {
        $table->integer('weekly_departures')->nullable();
    }
    if (!Schema::hasColumn('transit_stops', 'routes_count')) {
        $table->integer('routes_count')->nullable();
    }
});
```

After frequency import, backfill these columns:

```sql
-- Update transit_stops with frequency summaries for proximity scoring
UPDATE transit_stops ts
SET 
    stop_type = freq.mode_category,
    weekly_departures = freq.departures_06_20_total * 5,  -- weekday × 5 as proxy
    routes_count = freq.distinct_routes
FROM (
    SELECT gtfs_stop_id, 
           mode_category,
           departures_06_20_total,
           distinct_routes,
           ROW_NUMBER() OVER (PARTITION BY gtfs_stop_id ORDER BY departures_06_20_total DESC) as rn
    FROM transit_stop_frequencies
    WHERE day_type = 'weekday'
) freq
WHERE ts.gtfs_stop_id = freq.gtfs_stop_id
  AND freq.rn = 1;  -- Use the dominant mode for stops served by multiple modes
```

This means `ProximityScoreService::scoreTransit()` works unchanged — it reads `weekly_departures` and `stop_type` from `transit_stops`, which now have real data instead of NULL.

---

## OSM Ingestion Command Update

The OSM ingestion command (`ingest:osm-pois`) must skip transit categories once GTFS is the authority:

```php
class IngestOsmPois extends Command
{
    // Categories that are sourced from GTFS, not OSM
    private const GTFS_MANAGED_CATEGORIES = [
        'transit_stop', 'bus_stop', 'tram_stop',
        'rail_station', 'subway_station', 'transit',
    ];

    public function handle()
    {
        $category = $this->option('category');

        if ($category && in_array($category, self::GTFS_MANAGED_CATEGORIES)) {
            $this->warn("Category '{$category}' is managed by GTFS ingestion. Skipping.");
            $this->warn("Run 'php artisan ingest:gtfs' instead.");
            return;
        }

        if ($category === 'all' || !$category) {
            $this->info('Skipping transit categories (managed by GTFS)...');
            // ... existing logic, but skip GTFS_MANAGED_CATEGORIES
        }
    }
}
```

---

## Execution Order

### First Time (GTFS replaces OSM transit)

```bash
# 1. Run migration to add source/stop_type/weekly_departures columns
php artisan migrate

# 2. Run GTFS ingestion (handles clearing OSM transit + importing GTFS)
php artisan ingest:gtfs

# 3. Verify the transition
php artisan tinker
>>> TransitStop::where('source', 'gtfs')->count()   // ~47,000
>>> TransitStop::where('source', 'osm')->count()     // 0
>>> Poi::where('source', 'gtfs')->count()             // ~2,000-5,000 qualifying stops
>>> Poi::where('source', 'osm')->where('category', 'like', 'transit%')->count()  // 0

# 4. Normalize and recompute
php artisan normalize:indicators --year=2026
php artisan compute:scores --year=2026
```

### Subsequent Runs (Monthly GTFS Update)

```bash
# Same command — it clears old GTFS data and reimports
php artisan ingest:gtfs
php artisan normalize:indicators --year=2026
php artisan compute:scores --year=2026
```

Idempotent. Run it monthly, no manual cleanup needed.

---

## Verification

- [ ] After `ingest:gtfs`: zero rows in `transit_stops` with `source = 'osm'`
- [ ] After `ingest:gtfs`: zero rows in `pois` with `source = 'osm'` AND transit-related category
- [ ] `transit_stops` has ~47,000 rows with `source = 'gtfs'`
- [ ] `transit_stops.weekly_departures` is populated for all stops with departures
- [ ] `pois` has ~2,000-5,000 GTFS-sourced transit POIs (qualifying high-value stops only)
- [ ] Parks, grocery, cafes etc. in `pois` table are untouched (still `source = 'osm'`)
- [ ] `ProximityScoreService::scoreTransit()` works without code changes (reads from same table)
- [ ] Transit proximity score now uses frequency weighting (GTFS data) instead of flat scoring (OSM)
- [ ] `ingest:osm-pois --category=transit` prints a warning and exits
- [ ] `ingest:osm-pois --all` skips transit categories automatically
- [ ] Full pipeline runs in < 3 minutes (Python frequency computation is the bottleneck)
- [ ] Sundbyberg scores near top for transit (rail + subway + tram + bus)
- [ ] Rural Norrland DeSO has near-zero transit frequency but doesn't tank composite score (urbanity weighting)