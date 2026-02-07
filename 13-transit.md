# TASK: Public Transport Accessibility — GTFS Ingestion & Transit Scoring

## Context

Public transport accessibility is one of the strongest property value predictors in Sweden. A 2BR in Sundbyberg (8 min to T-Centralen) costs 2× what the same apartment costs in Märsta (40 min). The difference isn't crime, schools, or demographics — it's the commute. We need to capture this.

The good news: **we don't need to deal with 21 different regional operators.** Samtrafiken (the consortium of all Swedish regional transport authorities) publishes **GTFS Sverige 2** — a single unified GTFS feed covering every public transport operator in the country. 69 operators, ~47,000 stops, ~5,700 routes, CC0 license, updated daily, free API key from Trafiklab.

The hard news: raw GTFS data is a timetable, not a score. Converting "bus 176 departs stop 740012345 at 07:23" into "this DeSO has excellent transit access" requires real thinking about what matters.

---

## The Core Question: What Actually Matters for Property Value?

### What Doesn't Work: Counting Stops

A DeSO with 12 bus stops sounds better than one with 3. But what if those 12 stops are all on the same local line that runs twice an hour to a village center? And the 3 stops include a commuter rail station with 6-minute headways to Stockholm Central?

Stop count is noise. **Frequency-weighted reachability is signal.**

### What Matters (Ranked by Property Value Impact)

1. **Commute time to nearest major employment center** — The killer metric. How long does it take to get to work from here? A DeSO where you can reach Stockholm CBD in 20 minutes is worth dramatically more than one where it takes 55 minutes, even if the latter has "more stops."

2. **Service frequency (headways)** — A stop with a bus every 5 minutes is fundamentally different from one with a bus every 60 minutes. Frequency determines whether transit is a viable daily transport mode or an emergency fallback.

3. **Mode quality** — Rail > subway/tram > bus rapid transit > regular bus > on-demand/flex. Rail-connected areas command premiums because rail is faster, more reliable, and harder to cut. Rail doesn't get stuck in traffic.

4. **First/last mile** — How far do you walk to the nearest high-frequency stop? 300m vs 1200m is the difference between "I take transit daily" and "I drive."

5. **Service span** — Does transit run evenings and weekends? A bus that stops at 19:00 isn't real transit for anyone with a social life.

6. **Reliability** — Consistently on time vs. chronically delayed. (We can get this from GTFS-RT data but it's a stretch goal.)

---

## Step 1: Data Source — Trafiklab GTFS Sverige 2

### 1.1 What We're Using

| Field | Detail |
|---|---|
| Dataset | GTFS Sverige 2 |
| Provider | Trafiklab / Samtrafiken |
| URL | https://www.trafiklab.se/api/gtfs-datasets/gtfs-sverige-2/ |
| Coverage | All public transport operators in Sweden |
| Operators | ~69 (SL, Västtrafik, Skånetrafiken, SJ, MTRX, Vy, etc.) |
| Stops | ~47,000 |
| Routes | ~5,700 |
| Format | GTFS (zip of CSV files) |
| License | CC0 1.0 (public domain) |
| Update frequency | Daily |
| Auth | Free API key from Trafiklab |
| Size | ~40 MB compressed |

### 1.2 Why GTFS Sverige 2 Over GTFS Regional or Sweden 3

- **Sverige 2** has complete coverage of all operators. Regional and Sweden 3 are still catching up.
- We don't need shapes.txt (route geometry) or real-time data for scoring. We need stop locations and frequencies.
- Single file, single download, no operator-by-operator assembly.
- Sverige 2 is the established dataset with 5+ years of archives (via KoDa API) for historical analysis.

### 1.3 GTFS File Structure (What We Use)

From the zip, we need these files:

| File | What It Contains | Our Use |
|---|---|---|
| `stops.txt` | Stop locations (id, name, lat, lng) | Stop coordinates → DeSO assignment |
| `routes.txt` | Route definitions (id, name, type) | Mode classification (bus/rail/tram/ferry) |
| `trips.txt` | Individual trips on routes | Link routes to stop sequences |
| `stop_times.txt` | Arrival/departure times per stop per trip | Frequency calculation |
| `calendar.txt` | Service days (M-F, weekends) | Weekday vs weekend frequency |
| `calendar_dates.txt` | Exceptions (holidays) | Filter out non-representative days |
| `agency.txt` | Operator info | Operator name for display |

We do NOT need: `shapes.txt` (route geometry), `transfers.txt` (transfer rules), `fare_*.txt` (pricing).

### 1.4 API Key

Register at https://www.trafiklab.se/ to get a free API key. Store in `.env`:

```
TRAFIKLAB_API_KEY=your_key_here
```

Download URL:
```
https://opendata.samtrafiken.se/gtfs/sweden/sweden.zip?key={TRAFIKLAB_API_KEY}
```

---

## Step 2: What We Compute (Three Indicators)

### Indicator 1: Transit Frequency Score

**What:** How much transit service exists in/near this DeSO?

**Method:** For each DeSO, find all stops within the DeSO boundary (or within 400m of boundary for edge stops). For each stop, count the number of departures on a representative weekday (Tuesday or Wednesday, non-holiday) between 06:00-20:00. Sum across all stops, weight by mode:

```
frequency_score = Σ (departures_per_stop × mode_weight)

mode_weights:
  commuter_rail / intercity_rail: 3.0    (route_type 100-199)
  subway / metro:                 3.0    (route_type 400-499)  
  tram / light rail:              2.0    (route_type 900-999, 0)
  bus rapid transit:              1.5    (route_type 700-799 if identifiable)
  regular bus:                    1.0    (route_type 3, 200-299)
  ferry:                          0.5    (route_type 4, 1000-1099)
  on-demand / flex:               0.3    (route_type 1500+)
```

**Why weight by mode?** A commuter rail departure is worth more than a bus departure because: faster, more reliable, longer range, harder infrastructure (signals permanence — rail lines don't get canceled like bus routes).

**Normalization:** Percentile rank across all DeSOs. This becomes `transit_frequency_score` indicator.

### Indicator 2: Station Proximity Score

**What:** How close is the nearest high-quality transit stop?

**Method:** For each DeSO centroid, compute distance to:
- Nearest rail station (SJ, commuter rail, subway) → `distance_rail_m`
- Nearest high-frequency bus/tram stop (≥4 departures/hour in peak) → `distance_hf_transit_m`

Combine into a proximity score using distance decay:

```
proximity_score = 
    0.6 × max(0, 1 - (distance_rail_m / 5000)²) +     // Rail within 5km
    0.4 × max(0, 1 - (distance_hf_transit_m / 1500)²)  // HF transit within 1.5km
```

Quadratic decay: being 500m from a rail station is dramatically better than being 2km away, but the difference between 4km and 5km barely matters.

**Why this matters:** In Stockholm, the subway map essentially IS the property value map. Being within walking distance of a T-bana station is worth 15-20% on an apartment. Same for pendeltåg stations. In Gothenburg, tram proximity matters similarly.

**Normalization:** Percentile rank. Becomes `transit_proximity_score`.

### Indicator 3: Commute Time Estimate

**What:** Estimated public transit commute time to the nearest major employment center.

**Method:** This is the most valuable and most complex metric.

**Step A — Define employment centers:**

| Rank | Center | Coordinates | Regional Gravity |
|---|---|---|---|
| 1 | Stockholm T-Centralen | 59.3309, 18.0597 | National |
| 2 | Göteborg Centralstation | 57.7089, 11.9740 | Regional |
| 3 | Malmö Centralstation | 55.6092, 13.0007 | Regional |
| 4 | Uppsala Centralstation | 59.8585, 17.6448 | Sub-regional |
| 5 | Linköping Centralstation | 58.4165, 15.6253 | Sub-regional |
| 6 | Västerås Centralstation | 59.6099, 16.5448 | Sub-regional |
| 7 | Örebro Centralstation | 59.2753, 15.2134 | Sub-regional |
| 8 | Umeå Centralstation | 63.8258, 20.2630 | Northern |
| 9 | Lund Centralstation | 55.7087, 13.1870 | Sub-regional |
| 10 | Jönköping Resecentrum | 57.7826, 14.1618 | Sub-regional |

For each DeSO: find the nearest employment center, then estimate transit time.

**Step B — Estimate transit time:**

We have two approaches, from simple to sophisticated:

**Simple (Phase 1):** Straight-line distance to nearest center + mode-based speed estimate:
```
if has_rail_station_within_2km:
    estimated_time = (distance_to_center_km / 50) * 60 + 10  // Rail avg 50km/h + 10min access
elif has_hf_bus_within_500m:
    estimated_time = (distance_to_center_km / 25) * 60 + 10  // Bus avg 25km/h + 10min access
else:
    estimated_time = (distance_to_center_km / 20) * 60 + 20  // Slow transit + long access
```

This is a rough proxy but captures the right ordering: rail-connected suburbs beat bus-only suburbs beat isolated areas.

**Sophisticated (Phase 2):** Use OpenTripPlanner (OTP) with the GTFS data to compute actual routing. OTP can answer "what's the fastest trip from point A to point B departing at 08:00 on a Tuesday?" accounting for real routes, transfers, and walking. Run this for each DeSO centroid → nearest employment center. This gives actual commute times.

OTP is a Java application, so we'd run it as a Docker sidecar or use a hosted instance. This is a separate infrastructure task but would give us gold-standard commute data.

**Phase 1 is sufficient for launch.** The distance-based estimate with mode adjustment correlates well enough with actual commute times for scoring purposes. Phase 2 is a future enhancement.

**Normalization:** Inverse percentile rank (shorter commute = higher score). Becomes `transit_commute_score`.

---

## Step 3: Database Schema

### 3.1 Transit Stops Table

```php
Schema::create('transit_stops', function (Blueprint $table) {
    $table->id();
    $table->string('gtfs_stop_id', 30)->unique()->index();
    $table->string('name');
    $table->decimal('lat', 10, 7);
    $table->decimal('lng', 10, 7);
    $table->string('deso_code', 10)->nullable()->index();   // Resolved via ST_Contains
    $table->string('kommun_code', 4)->nullable();
    $table->string('parent_station', 30)->nullable();        // GTFS parent_station field
    $table->unsignedTinyInteger('location_type')->default(0); // 0=stop, 1=station
    $table->timestamps();
});

// Spatial column + index
DB::statement("SELECT AddGeometryColumn('public', 'transit_stops', 'geom', 4326, 'POINT', 2)");
DB::statement("CREATE INDEX transit_stops_geom_idx ON transit_stops USING GIST (geom)");
```

### 3.2 Transit Stop Frequencies Table

Pre-computed: how many departures does each stop have, by mode and day type?

```php
Schema::create('transit_stop_frequencies', function (Blueprint $table) {
    $table->id();
    $table->string('gtfs_stop_id', 30)->index();
    $table->string('feed_version', 20);                      // Which GTFS download
    $table->string('day_type', 10);                          // "weekday", "saturday", "sunday"
    $table->string('route_type', 10);                        // GTFS route_type (100, 3, 900, etc.)
    $table->string('mode_category', 20);                     // "rail", "subway", "tram", "bus", "ferry"
    $table->integer('departures_06_09')->default(0);         // Morning peak
    $table->integer('departures_09_15')->default(0);         // Midday
    $table->integer('departures_15_18')->default(0);         // Afternoon peak
    $table->integer('departures_18_22')->default(0);         // Evening
    $table->integer('departures_06_20_total')->default(0);   // Full service day
    $table->integer('distinct_routes')->default(0);          // How many different routes serve this stop
    $table->timestamps();

    $table->unique(['gtfs_stop_id', 'feed_version', 'day_type', 'mode_category']);
});
```

### 3.3 Employment Centers Table

```php
Schema::create('employment_centers', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('rank', 20);          // "national", "regional", "sub-regional", "northern"
    $table->decimal('lat', 10, 7);
    $table->decimal('lng', 10, 7);
    $table->integer('gravity_radius_km'); // How far this center's influence extends
    $table->timestamps();
});

DB::statement("SELECT AddGeometryColumn('public', 'employment_centers', 'geom', 4326, 'POINT', 2)");
```

### 3.4 Indicators

Add to the indicators seeder:

| slug | name | unit | direction | weight | category |
|---|---|---|---|---|---|
| `transit_frequency` | Transit Service Frequency | index | positive | 0.05 | transport |
| `transit_proximity` | Transit Station Proximity | index | positive | 0.08 | transport |
| `transit_commute` | Estimated Commute Time | minutes | negative | 0.07 | transport |

**Total transport weight: 0.20** — significant but not dominant. Steal from unallocated budget.

Updated weight budget:

| Category | Weight |
|---|---|
| Income (SCB) | 0.20 |
| Employment (SCB) | 0.10 |
| Education — demographics (SCB) | 0.10 |
| Education — school quality (Skolverket) | 0.25 |
| Transport (GTFS) | 0.20 |
| **Unallocated** (crime, debt, POI) | **0.15** |

---

## Step 4: Ingestion Pipeline

### 4.1 Artisan Command: Download & Parse GTFS

```bash
php artisan ingest:gtfs [--feed=sverige2]
```

**Phase 1: Download**
```php
$url = "https://opendata.samtrafiken.se/gtfs/sweden/sweden.zip?key=" . config('services.trafiklab.api_key');
$zipPath = Storage::path('data/raw/gtfs_sverige2.zip');
Http::withOptions(['sink' => $zipPath])->get($url);
```

**Phase 2: Extract & Import Stops**

Parse `stops.txt` — a CSV with ~47,000 rows:
```
stop_id,stop_name,stop_lat,stop_lon,location_type,parent_station
740012345,Sundbybergs station,59.361282,17.971893,1,
740012346,Sundbybergs station spår 1,59.361282,17.971893,0,740012345
```

Import only `location_type = 0` (actual stops) and `location_type = 1` (stations). Skip entrances/exits (type 2) and generic nodes (type 3).

**Phase 3: Compute Frequencies**

This is the heavy part. `stop_times.txt` has millions of rows (every departure at every stop):
```
trip_id,arrival_time,departure_time,stop_id,stop_sequence
12345,07:23:00,07:23:00,740012345,5
12345,07:28:00,07:28:00,740012346,6
```

Algorithm:
1. From `calendar.txt`, identify service IDs active on a representative weekday (pick a typical Tuesday 4-6 weeks from now, exclude holidays via `calendar_dates.txt`)
2. From `trips.txt`, get all trips running on that service day, along with their `route_id`
3. From `routes.txt`, get the `route_type` for each route
4. From `stop_times.txt`, for each stop, count departures in each time window, grouped by route mode
5. Bulk insert into `transit_stop_frequencies`

**Performance concern:** `stop_times.txt` for all of Sweden could be 5-10 million rows. Don't load into memory. Stream-parse the CSV:

```php
$handle = fopen($stopTimesPath, 'r');
$header = fgetcsv($handle);

$counts = []; // [stop_id][mode_category][time_bucket] => count

while (($row = fgetcsv($handle)) !== false) {
    $tripId = $row[$tripIdCol];
    
    // Check if this trip runs on our target service day
    if (!isset($activeTrips[$tripId])) continue;
    
    $stopId = $row[$stopIdCol];
    $departureTime = $row[$departureTimeCol];
    $routeType = $tripRouteTypes[$tripId];
    $mode = $this->classifyMode($routeType);
    $bucket = $this->timeBucket($departureTime);
    
    $counts[$stopId][$mode][$bucket] = ($counts[$stopId][$mode][$bucket] ?? 0) + 1;
}
```

**Batch this.** Build the lookup maps (active trips, route types) first by scanning `calendar.txt`, `trips.txt`, `routes.txt` (all small). Then single-pass through `stop_times.txt` (large).

### 4.2 DeSO Assignment

After importing stops, spatial join to DeSOs:

```sql
UPDATE transit_stops ts
SET deso_code = d.deso_code
FROM deso_areas d
WHERE ST_Contains(d.geom, ts.geom)
  AND ts.geom IS NOT NULL
  AND ts.deso_code IS NULL;
```

### 4.3 Mode Classification

GTFS `route_type` values (extended types used by Trafiklab):

```php
private function classifyMode(int $routeType): string
{
    return match(true) {
        $routeType >= 100 && $routeType < 200 => 'rail',       // Railway
        $routeType >= 200 && $routeType < 300 => 'bus',        // Coach / long-distance bus
        $routeType == 3 || ($routeType >= 700 && $routeType < 800) => 'bus',  // City bus
        $routeType >= 400 && $routeType < 500 => 'subway',     // Metro/subway
        $routeType == 0 || ($routeType >= 900 && $routeType < 1000) => 'tram', // Tram/light rail
        $routeType == 4 || ($routeType >= 1000 && $routeType < 1100) => 'ferry',
        $routeType >= 1500 => 'on_demand',                     // Flex / demand-responsive
        default => 'bus',                                        // Fallback
    };
}
```

---

## Step 5: Aggregation to DeSO Indicators

### 5.1 Artisan Command

```bash
php artisan aggregate:transit-indicators [--feed-version=2026-02]
```

### 5.2 Transit Frequency Score (per DeSO)

```php
foreach ($desoCodes as $desoCode) {
    // Get all stops in this DeSO
    $stops = TransitStop::where('deso_code', $desoCode)->pluck('gtfs_stop_id');
    
    // Get weekday frequencies for these stops
    $frequencies = TransitStopFrequency::whereIn('gtfs_stop_id', $stops)
        ->where('day_type', 'weekday')
        ->get();
    
    $weightedDepartures = $frequencies->sum(function ($f) {
        $modeWeight = match($f->mode_category) {
            'rail' => 3.0,
            'subway' => 3.0,
            'tram' => 2.0,
            'bus' => 1.0,
            'ferry' => 0.5,
            'on_demand' => 0.3,
            default => 1.0,
        };
        return $f->departures_06_20_total * $modeWeight;
    });
    
    // Store as indicator raw value
    IndicatorValue::updateOrCreate(
        ['deso_code' => $desoCode, 'indicator_id' => $freqIndicator->id, 'year' => $year],
        ['raw_value' => $weightedDepartures]
    );
}
```

### 5.3 Transit Proximity Score (per DeSO)

```sql
-- Find nearest rail station to each DeSO centroid
SELECT d.deso_code,
       MIN(ST_Distance(ST_Centroid(d.geom)::geography, ts.geom::geography)) as distance_rail_m
FROM deso_areas d
CROSS JOIN LATERAL (
    SELECT ts.geom
    FROM transit_stops ts
    JOIN transit_stop_frequencies tsf ON tsf.gtfs_stop_id = ts.gtfs_stop_id
    WHERE tsf.mode_category IN ('rail', 'subway')
      AND tsf.day_type = 'weekday'
      AND tsf.departures_06_20_total > 0
    ORDER BY ts.geom <-> ST_Centroid(d.geom)
    LIMIT 1
) ts
GROUP BY d.deso_code;
```

Similar query for high-frequency bus/tram (≥4 departures/hour = ≥56 departures in 06:00-20:00).

Then compute:
```php
$proximityScore = 
    0.6 * max(0, 1 - pow($distanceRail / 5000, 2)) +
    0.4 * max(0, 1 - pow($distanceHfTransit / 1500, 2));
```

### 5.4 Commute Time Estimate (per DeSO)

```php
foreach ($desoCodes as $desoCode) {
    $centroid = DB::selectOne(
        "SELECT ST_X(ST_Centroid(geom)) as lng, ST_Y(ST_Centroid(geom)) as lat 
         FROM deso_areas WHERE deso_code = ?", [$desoCode]
    );
    
    // Find nearest employment center
    $nearest = EmploymentCenter::orderByRaw(
        "geom <-> ST_SetSRID(ST_MakePoint(?, ?), 4326)", 
        [$centroid->lng, $centroid->lat]
    )->first();
    
    $distanceKm = DB::selectOne(
        "SELECT ST_Distance(
            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
        ) / 1000 as km",
        [$centroid->lng, $centroid->lat, $nearest->lng, $nearest->lat]
    )->km;
    
    // Check what transit is available in this DeSO
    $hasRail = TransitStopFrequency::whereIn('gtfs_stop_id', 
            TransitStop::where('deso_code', $desoCode)->pluck('gtfs_stop_id')
        )
        ->where('mode_category', 'rail')
        ->where('day_type', 'weekday')
        ->where('departures_06_20_total', '>', 0)
        ->exists();
    
    $hasHfTransit = /* similar check for high-freq bus/tram */;
    
    // Estimate commute time
    if ($hasRail) {
        $commuteMin = ($distanceKm / 50) * 60 + 10;  // Rail speed + access time
    } elseif ($hasHfTransit) {
        $commuteMin = ($distanceKm / 25) * 60 + 10;
    } else {
        $commuteMin = ($distanceKm / 20) * 60 + 20;
    }
    
    // Cap at reasonable maximum
    $commuteMin = min($commuteMin, 180);
    
    IndicatorValue::updateOrCreate(
        ['deso_code' => $desoCode, 'indicator_id' => $commuteIndicator->id, 'year' => $year],
        ['raw_value' => round($commuteMin, 1)]
    );
}
```

---

## Step 6: The Urban/Rural Problem

### 6.1 The Problem

Transit scoring inherently favors urban areas. Stockholm's inner city gets perfect transit scores. Rural Norrland gets zero. This is... correct for property value prediction, but it creates a problem: transit dominates the composite score for rural areas, dragging them down even when they're perfectly nice places to live (with a car).

### 6.2 The Solution: Context-Aware Weighting

The transit indicators should have **reduced effective weight for DeSOs where car dependency is the norm.** Two approaches:

**Approach A: Urbanity-based weight scaling**

SCB classifies all DeSOs into urbanity categories. Use this to scale transit weight:
- Urban (tätort >50,000): full weight (1.0×)
- Small town (tätort 10,000-50,000): 0.7× weight
- Rural town (tätort 1,000-10,000): 0.4× weight
- Rural/wilderness: 0.2× weight

This means transit matters a lot in scoring Stockholm suburbs but barely affects scoring a DeSO in inland Norrland. This is realistic — nobody in rural Jämtland chooses where to live based on bus frequency.

**Approach B: Peer-group normalization**

Instead of one national percentile rank, compute percentile within urbanity peer groups. A rural DeSO with the best transit in its class (maybe a bus every hour to the regional center) gets a high score within the rural group, even though it would score terribly nationally.

**Recommendation:** Use Approach A. It's simpler, more defensible, and aligns with how transit actually affects property values. Peer-group ranking sounds fairer but hides the real signal: urban areas genuinely have better transit, and that genuinely increases property value.

Implementation: The `ScoringService` already has per-indicator weights. Extend it to support per-DeSO weight modifiers based on urbanity classification. Store urbanity tier in `deso_areas` (we may already have this from SCB data).

---

## Step 7: Transit Stops as POIs

### 7.1 Integration with POI Display System

Transit stops should also appear in the POI display system (from task-poi-display.md). But not all 47,000 stops — that would overwhelm everything.

**Which stops become POIs:**

| Category | Criteria | Display Tier | Icon |
|---|---|---|---|
| Major rail stations | Intercity rail, >20 departures/day | Tier 1 (zoom 8+) | `train-front` |
| Commuter rail / subway stations | Pendeltåg, T-bana | Tier 2 (zoom 10+) | `train` |
| Tram stops | Tram/spårvagn | Tier 3 (zoom 12+) | `tram-front` |
| High-frequency bus stops | ≥4 departures/hour peak | Tier 4 (zoom 14+) | `bus` |

Regular bus stops (the majority of 47,000) do NOT become POIs — they're too numerous and too low-value individually. They contribute to the DeSO-level transit frequency score but don't merit individual map markers.

### 7.2 Insert into POIs Table

After GTFS import, insert qualifying stops into the `pois` table:

```php
// Major rail stations → POI
$majorStations = TransitStop::whereIn('gtfs_stop_id',
    TransitStopFrequency::where('mode_category', 'rail')
        ->where('day_type', 'weekday')
        ->where('departures_06_20_total', '>=', 20)
        ->pluck('gtfs_stop_id')
)
->where('location_type', 1)  // Station level, not individual platforms
->get();

foreach ($majorStations as $station) {
    Poi::updateOrCreate(
        ['source_id' => 'gtfs:' . $station->gtfs_stop_id],
        [
            'name' => $station->name,
            'poi_type' => 'rail_station_major',
            'category' => 'transport',
            'sentiment' => 'positive',
            'lat' => $station->lat,
            'lng' => $station->lng,
            'display_tier' => 1,
            'extra_data' => json_encode([
                'departures_weekday' => $station->frequencies->sum('departures_06_20_total'),
                'modes' => $station->frequencies->pluck('mode_category')->unique()->values(),
                'operators' => /* from agency.txt */,
            ]),
        ]
    );
}
```

---

## Step 8: The Full Pipeline

### 8.1 Commands

```bash
# 1. Download and parse GTFS feed
php artisan ingest:gtfs

# 2. Assign stops to DeSOs (spatial join)
# (happens automatically in step 1)

# 3. Compute per-DeSO transit indicators
php artisan aggregate:transit-indicators

# 4. Insert qualifying stops as POIs
php artisan process:transit-pois

# 5. Normalize all indicators
php artisan normalize:indicators --year=2026

# 6. Recompute scores
php artisan compute:scores --year=2026
```

### 8.2 Schedule

GTFS data changes slowly (monthly timetable updates). Run monthly:

```php
$schedule->command('ingest:gtfs')->monthly();
$schedule->command('aggregate:transit-indicators')->monthly();
$schedule->command('process:transit-pois')->monthly();
```

### 8.3 Performance Notes

- `stop_times.txt` parsing: stream, don't load into memory. Build lookup maps first, single-pass the big file.
- Spatial joins (47k stops × 6,160 DeSOs): use PostGIS ST_Contains with GIST index. Should take <30 seconds.
- Frequency aggregation: ~47,000 stops × ~5 modes × 4 time buckets = 940k rows in transit_stop_frequencies. Small.
- Commute time: 6,160 DeSOs × 1 nearest-center query each. Use `<->` operator with GIST index. Fast.

---

## Step 9: Verification

### 9.1 Sanity Checks

```sql
-- Stop import
SELECT COUNT(*) FROM transit_stops;  -- Expect ~47,000
SELECT COUNT(*) FROM transit_stops WHERE deso_code IS NOT NULL;  -- Most should resolve

-- Frequency sanity
SELECT mode_category, 
       COUNT(DISTINCT gtfs_stop_id) as stops,
       SUM(departures_06_20_total) as total_departures
FROM transit_stop_frequencies
WHERE day_type = 'weekday'
GROUP BY mode_category;
-- Expect: bus has most stops, rail has fewer but more departures per stop

-- Top transit DeSOs should be urban cores
SELECT iv.deso_code, da.kommun_name, iv.raw_value
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
JOIN deso_areas da ON da.deso_code = iv.deso_code
WHERE i.slug = 'transit_frequency'
ORDER BY iv.raw_value DESC LIMIT 15;
-- Expect: Stockholm (Sergels torg, Slussen, T-Centralen DeSOs), Göteborg C, Malmö C

-- Worst commute DeSOs should be remote rural
SELECT iv.deso_code, da.kommun_name, iv.raw_value as commute_min
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
JOIN deso_areas da ON da.deso_code = iv.deso_code
WHERE i.slug = 'transit_commute'
ORDER BY iv.raw_value DESC LIMIT 15;
-- Expect: remote Norrland, inland Småland, Gotland interior

-- Proximity: DeSOs near T-Centralen should score ~1.0
SELECT iv.deso_code, da.kommun_name, iv.raw_value
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
JOIN deso_areas da ON da.deso_code = iv.deso_code
WHERE i.slug = 'transit_proximity'
ORDER BY iv.raw_value DESC LIMIT 15;
```

### 9.2 The Sundbyberg Test

Sundbyberg is the ultimate transit-rich suburb: commuter rail (pendeltåg), subway (T-bana Blå), tram (Tvärbanan), and tons of buses. It should score near the top on all three transit indicators. If it doesn't, something is wrong.

### 9.3 The Arjeplog Test  

Arjeplog in inland Norrbotten has ~3,000 people spread over 14,000 km². Public transit is essentially nonexistent. Its transit scores should be near zero — but with urbanity weighting, this should NOT tank its composite score. If Arjeplog's composite score drops dramatically after adding transit, the urbanity weighting isn't working.

---

## Notes for the Agent

### The 47,000 Stop IDs

GTFS Sverige 2 uses national stop IDs (rikshållplatser) starting with `740`. These are stable IDs maintained by Samtrafiken. Each "stop area" (like "Sundbybergs station") may have multiple physical stops (platforms, bus bays) sharing a parent. Use `parent_station` to group them — frequencies should be summed at the station level, not double-counted per platform.

### Extended Route Types

Trafiklab uses GTFS extended route types (100-series numbers, not just 0-7). The classification varies by operator — some use specific types (101 = high-speed rail), others use generic (100 = all rail). Don't over-parse. Group into the 6 mode categories and move on.

### Watch Out For

- **Overnight services:** Some trips have departure_time > 24:00:00 (e.g., "25:15:00" = 1:15 AM the next day). Handle this in time bucket classification.
- **Seasonal services:** Some routes only run in summer. Use a representative date well within the main service period.
- **Flex/on-demand:** Growing in rural Sweden. These have irregular schedules. Count them but weight low.
- **Duplicate stops:** A station may appear multiple times (once per platform). Group by `parent_station` before counting.
- **Ferry stops:** Include but weight low. Ferries are slow and infrequent but some island communities depend on them (Gotlandsbåten, Waxholmsbolaget).

### What NOT to Do

- Don't try real-time data (GTFS-RT) for scoring — we need static timetables, not live positions
- Don't compute actual routing (OTP) in Phase 1 — the distance-based estimate is good enough
- Don't weight all modes equally — rail is fundamentally more valuable than bus
- Don't show all 47,000 stops on the map — only qualifying stations/stops via the POI system
- Don't normalize transit scores nationally without urbanity context — it'll unfairly punish every rural DeSO
- Don't parse `shapes.txt` — we don't need route geometries for scoring

### Phase 2 Enhancements (Not Now)

- **OpenTripPlanner routing:** Actual computed commute times instead of distance estimates
- **Isochrone maps:** "Show me everywhere I can reach in 30 minutes from this DeSO"
- **Service reliability:** Use GTFS-RT historical data to compute on-time performance per stop
- **Weekend/evening scores:** Separate indicators for off-peak transit (matters for social quality of life)
- **Transfer penalty:** Account for having to change buses/trains (a 30-min journey with 2 transfers is worse than 30-min direct)
- **Walking isochrones to stops:** Use road network distance instead of straight-line for proximity scoring