# TASK: Per-Coordinate Proximity Scoring

## What This Is

Right now, every point inside a DeSO gets the same score. Drop a pin 50 meters from Sweden's best school or 2km away — same number. That's because all our indicators are **area-level statistics** (income, employment, education for the whole DeSO).

This task adds a second scoring layer: **proximity indicators** that are unique to the exact coordinates where the user drops a pin. The final score shown in the sidebar becomes a blend of area-level data (what kind of neighborhood is this?) and proximity data (what's actually near THIS specific address?).

**Think of it as:** the current score tells you "this is a good neighborhood." The proximity score tells you "and this specific address within it is especially good because you're 200m from a great school, 300m from a park, and 150m from a metro station."

Two addresses in the same DeSO can now have different scores.

---

## How It Works — The Two-Layer Model

```
LAYER 1: Area Score (pre-computed, per DeSO)
  Income, employment, education, crime, debt
  Same for everyone in the DeSO
  Stored in composite_scores table
  Shown on the heatmap tiles

LAYER 2: Proximity Score (computed on the fly, per coordinate)
  Distance to nearest school (and its quality)
  Distance to nearest park / green space
  Distance to nearest transit stop (and frequency)
  Nearby POI mix (positive and negative)
  Computed when user drops pin
  Adjusts the area score up or down

FINAL SCORE = Area Score × area_weight + Proximity Score × proximity_weight
```

The area score is the foundation. The proximity score adjusts it. Suggested split: **70% area, 30% proximity**. This means proximity can move the score by roughly ±15 points. A DeSO with area score 65 could show anywhere from ~50 to ~80 depending on exact pin location.

The heatmap tiles still show the area-level score (pre-computed, can't be per-pixel). The sidebar shows the blended final score for the pin's exact location.

---

## Step 1: Proximity Indicator Categories

### 1.1 What Gets Measured

Each proximity indicator answers: "How good is the nearest [thing] and how far away is it?"

| Category | What we measure | Max distance | Direction | Weight |
|---|---|---|---|---|
| School quality | Nearest grundskola's meritvärde, distance-decayed | 2 km | positive | 0.10 |
| Green space | Distance to nearest park / nature area | 1 km | positive | 0.04 |
| Transit access | Nearest stop + service frequency | 1 km | positive | 0.05 |
| Grocery | Distance to nearest grocery store | 1 km | positive | 0.03 |
| Negative POIs | Count of negative POIs within radius, distance-weighted | 500m | negative | 0.04 |
| Positive POIs | Count of positive POIs within radius, distance-weighted | 1 km | positive | 0.04 |
| **Total proximity weight** | | | | **0.30** |

The area-level indicators keep their current weights but are rescaled so area total = 0.70. Current area weights sum to 0.65 (income 0.20, employment 0.10, education 0.10, school quality 0.25). Rescale: each multiplied by 0.70/0.65 ≈ 1.077. Or simpler: just set area_weight = 0.70 and proximity_weight = 0.30 and normalize each layer to 0-100 internally before blending.

### 1.2 The Distance Decay Function

This is the core math. Amenities close by are worth more than distant ones. Walk Score uses a polynomial decay. We use a simpler linear decay:

```
decay(distance, max_distance) = max(0, 1 - distance / max_distance)
```

- 0 meters away → decay = 1.0 (full value)
- Half of max_distance → decay = 0.5
- At max_distance → decay = 0.0 (no value)
- Beyond max_distance → decay = 0.0

Example: school with meritvärde 260 at 400m, max_distance 2000m:
```
decay = 1 - 400/2000 = 0.80
score_contribution = normalized_merit × decay = 0.80 × 0.80 = 0.64
```

Same school at 1600m:
```
decay = 1 - 1600/2000 = 0.20
score_contribution = 0.80 × 0.20 = 0.16
```

The school is the same quality, but being far from it matters. This is how real estate pricing actually works — the premium for being near a top school drops steeply with distance.

---

## Step 2: Data Requirements

### 2.1 What We Already Have

| Data | Table | Has coordinates? | Status |
|---|---|---|---|
| Schools + quality stats | `schools`, `school_statistics` | Yes (lat/lng + PostGIS geom) | ✅ Implemented |
| DeSO boundaries | `deso_areas` | Yes (PostGIS polygons) | ✅ Implemented |
| Area-level indicators | `indicator_values` | Per DeSO | ✅ Implemented |

### 2.2 What We Need to Ingest

| Data | Source | Coordinates? | Effort |
|---|---|---|---|
| Parks / green spaces | OpenStreetMap Overpass API | Yes | Medium |
| Transit stops | GTFS Sverige 2 | Yes | Medium |
| Grocery stores | OpenStreetMap | Yes | Low |
| Negative POIs | OpenStreetMap + Google Places | Yes | Medium |
| Positive POIs | OpenStreetMap + Google Places | Yes | Medium |

### 2.3 POI Table

We need a general-purpose table for all point-of-interest data:

```php
Schema::create('pois', function (Blueprint $table) {
    $table->id();
    $table->string('source', 40);                    // 'osm', 'google', 'gtfs'
    $table->string('source_id', 100)->nullable();     // OSM node ID, Google place_id, etc.
    $table->string('category', 40)->index();          // 'park', 'transit_stop', 'grocery', etc.
    $table->string('subcategory', 60)->nullable();    // 'nature_reserve', 'bus_stop', 'ica_maxi', etc.
    $table->string('name')->nullable();
    $table->decimal('lat', 10, 7);
    $table->decimal('lng', 10, 7);
    $table->string('signal', 20)->default('positive'); // 'positive', 'negative', 'neutral'
    $table->json('metadata')->nullable();             // Extra data (opening_hours, frequency, etc.)
    $table->timestamps();

    $table->unique(['source', 'source_id']);
});

// Spatial column + index
DB::statement("SELECT AddGeometryColumn('public', 'pois', 'geom', 4326, 'POINT', 2)");
DB::statement("CREATE INDEX pois_geom_idx ON pois USING GIST (geom)");
DB::statement("CREATE INDEX pois_category_idx ON pois (category)");
```

### 2.4 Transit Stops Table

Transit stops need extra data (service frequency) that doesn't fit cleanly in the generic POI table:

```php
Schema::create('transit_stops', function (Blueprint $table) {
    $table->id();
    $table->string('stop_id', 50)->unique();          // GTFS stop_id
    $table->string('stop_name');
    $table->string('stop_type', 30)->nullable();       // 'bus', 'metro', 'tram', 'train', 'ferry'
    $table->decimal('lat', 10, 7);
    $table->decimal('lng', 10, 7);
    $table->integer('weekly_departures')->nullable();  // Total departures per week
    $table->integer('routes_count')->nullable();       // Number of distinct routes
    $table->string('agency', 100)->nullable();         // 'SL', 'Västtrafik', etc.
    $table->json('metadata')->nullable();
    $table->timestamps();
});

DB::statement("SELECT AddGeometryColumn('public', 'transit_stops', 'geom', 4326, 'POINT', 2)");
DB::statement("CREATE INDEX transit_stops_geom_idx ON transit_stops USING GIST (geom)");
```

---

## Step 3: Data Ingestion Commands

### 3.1 Parks and Green Spaces

```bash
php artisan ingest:osm-pois --category=parks
```

Overpass API query:
```
[out:json][timeout:180];
area["ISO3166-1"="SE"]->.sweden;
(
  way["leisure"="park"](area.sweden);
  way["leisure"="nature_reserve"](area.sweden);
  way["leisure"="garden"](area.sweden);
  way["landuse"="forest"]["access"!="private"](area.sweden);
  relation["leisure"="park"](area.sweden);
);
out center;
```

Store as POIs with category `park`. Use the center point of each polygon. For large parks/forests, also store the boundary polygon in metadata so we can compute distance-to-edge rather than distance-to-center.

### 3.2 Transit Stops

```bash
php artisan ingest:gtfs-stops
```

Download GTFS Sverige 2 from Trafiklab (free API key): `https://opendata.samtrafiken.se/gtfs-sweden/sweden.zip`

Parse `stops.txt` for coordinates and `stop_times.txt` to compute weekly departures per stop. Store in `transit_stops` table.

**Simplified alternative for v1:** Just fetch transit stops from OSM:
```
[out:json][timeout:180];
area["ISO3166-1"="SE"]->.sweden;
(
  node["public_transport"="stop_position"](area.sweden);
  node["highway"="bus_stop"](area.sweden);
  node["railway"="station"](area.sweden);
  node["railway"="tram_stop"](area.sweden);
  node["railway"="halt"](area.sweden);
);
out;
```

This gives locations but not frequency. For v1, presence of a stop within walking distance is already valuable. Frequency data can be added later from GTFS.

### 3.3 Grocery Stores

```bash
php artisan ingest:osm-pois --category=grocery
```

```
[out:json][timeout:120];
area["ISO3166-1"="SE"]->.sweden;
(
  node["shop"="supermarket"](area.sweden);
  way["shop"="supermarket"](area.sweden);
  node["shop"="convenience"](area.sweden);
);
out center;
```

### 3.4 Negative POIs

```bash
php artisan ingest:osm-pois --category=negative
```

```
[out:json][timeout:120];
area["ISO3166-1"="SE"]->.sweden;
(
  node["shop"="pawnbroker"](area.sweden);
  node["amenity"="gambling"](area.sweden);
  node["shop"="betting"](area.sweden);
  node["amenity"="nightclub"](area.sweden);
);
out center;
```

Store with `signal = 'negative'`.

### 3.5 Positive POIs

```bash
php artisan ingest:osm-pois --category=positive
```

```
[out:json][timeout:120];
area["ISO3166-1"="SE"]->.sweden;
(
  node["leisure"="fitness_centre"](area.sweden);
  way["leisure"="fitness_centre"](area.sweden);
  node["amenity"="cafe"]["cuisine"="coffee_shop"](area.sweden);
  node["shop"="health_food"](area.sweden);
  way["leisure"="sports_centre"](area.sweden);
);
out center;
```

Store with `signal = 'positive'`.

### 3.6 Umbrella Command

```bash
php artisan ingest:pois --all
# Runs all POI categories in sequence
```

---

## Step 4: Proximity Scoring Service

### 4.1 ProximityScoreService

Create `app/Services/ProximityScoreService.php`:

```php
class ProximityScoreService
{
    /**
     * Compute proximity scores for a specific coordinate.
     * Returns a 0-100 score and breakdown of each proximity factor.
     * 
     * This runs on every pin drop — must be fast (<200ms).
     */
    public function score(float $lat, float $lng): ProximityResult
    {
        return new ProximityResult(
            school: $this->scoreSchool($lat, $lng),
            greenSpace: $this->scoreGreenSpace($lat, $lng),
            transit: $this->scoreTransit($lat, $lng),
            grocery: $this->scoreGrocery($lat, $lng),
            negativePoi: $this->scoreNegativePois($lat, $lng),
            positivePoi: $this->scorePositivePois($lat, $lng),
        );
    }
}
```

### 4.2 School Proximity Score

The most important proximity indicator. Two things matter: **how good** is the nearest school, and **how close** is it.

```php
private function scoreSchool(float $lat, float $lng): ProximityFactor
{
    $maxDistance = 2000; // 2km

    // Find nearest grundskola schools within 2km
    $schools = DB::select("
        SELECT s.name, s.school_unit_code,
               ss.merit_value_17,
               ST_Distance(
                   s.geom::geography,
                   ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
               ) as distance_m
        FROM schools s
        LEFT JOIN school_statistics ss ON ss.school_unit_code = s.school_unit_code
            AND ss.academic_year = (
                SELECT MAX(academic_year) FROM school_statistics
                WHERE school_unit_code = s.school_unit_code
            )
        WHERE s.status = 'active'
          AND s.type_of_schooling ILIKE '%grundskola%'
          AND s.geom IS NOT NULL
          AND ST_DWithin(
              s.geom::geography,
              ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
              ?
          )
        ORDER BY distance_m
        LIMIT 5
    ", [$lng, $lat, $lng, $lat, $maxDistance]);

    if (empty($schools)) {
        return new ProximityFactor(
            slug: 'school_proximity',
            score: 0,
            details: ['message' => 'No grundskola within 2km']
        );
    }

    // Score = best school's quality × distance decay
    // If multiple schools nearby, take the best one (parents choose)
    $bestScore = 0;
    $bestSchool = null;

    foreach ($schools as $school) {
        if ($school->merit_value_17 === null) continue;

        // Normalize merit value: 150=0, 280=1 (roughly)
        $qualityNorm = min(1.0, max(0, ($school->merit_value_17 - 150) / 130));

        // Distance decay
        $decay = max(0, 1 - $school->distance_m / $maxDistance);

        $combined = $qualityNorm * $decay;

        if ($combined > $bestScore) {
            $bestScore = $combined;
            $bestSchool = $school;
        }
    }

    return new ProximityFactor(
        slug: 'school_proximity',
        score: round($bestScore * 100),  // 0-100
        details: [
            'nearest_school' => $bestSchool?->name,
            'nearest_merit' => $bestSchool?->merit_value_17,
            'nearest_distance_m' => round($bestSchool?->distance_m),
            'schools_within_2km' => count($schools),
        ]
    );
}
```

### 4.3 Green Space Proximity Score

```php
private function scoreGreenSpace(float $lat, float $lng): ProximityFactor
{
    $maxDistance = 1000; // 1km

    $nearest = DB::selectOne("
        SELECT name,
               ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m
        FROM pois
        WHERE category = 'park'
          AND ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
        ORDER BY geom <-> ST_SetSRID(ST_MakePoint(?, ?), 4326)
        LIMIT 1
    ", [$lng, $lat, $lng, $lat, $maxDistance, $lng, $lat]);

    if (!$nearest) {
        return new ProximityFactor(slug: 'green_space', score: 0, details: ['message' => 'No park within 1km']);
    }

    $decay = max(0, 1 - $nearest->distance_m / $maxDistance);

    return new ProximityFactor(
        slug: 'green_space',
        score: round($decay * 100),
        details: [
            'nearest_park' => $nearest->name,
            'distance_m' => round($nearest->distance_m),
        ]
    );
}
```

### 4.4 Transit Proximity Score

```php
private function scoreTransit(float $lat, float $lng): ProximityFactor
{
    $maxDistance = 1000; // 1km

    // Find all transit stops within 1km
    $stops = DB::select("
        SELECT stop_name, stop_type, weekly_departures,
               ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m
        FROM transit_stops
        WHERE ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
        ORDER BY distance_m
        LIMIT 10
    ", [$lng, $lat, $lng, $lat, $maxDistance]);

    if (empty($stops)) {
        return new ProximityFactor(slug: 'transit', score: 0, details: ['message' => 'No transit within 1km']);
    }

    // Score based on: closest stop distance + mode bonus + frequency
    $score = 0;
    foreach ($stops as $stop) {
        $decay = max(0, 1 - $stop->distance_m / $maxDistance);

        // Mode weight: rail > tram > bus
        $modeWeight = match($stop->stop_type) {
            'metro', 'train' => 1.5,
            'tram' => 1.2,
            default => 1.0,
        };

        // Frequency bonus (0-1): weekly_departures normalized
        // 100+ departures/week = full bonus. 0 = no bonus.
        $freqBonus = $stop->weekly_departures
            ? min(1.0, $stop->weekly_departures / 100)
            : 0.5; // Default if no frequency data

        $stopScore = $decay * $modeWeight * (0.5 + 0.5 * $freqBonus);
        $score = max($score, $stopScore); // Best stop wins
    }

    return new ProximityFactor(
        slug: 'transit',
        score: round(min(100, $score * 100)),
        details: [
            'nearest_stop' => $stops[0]->stop_name,
            'nearest_type' => $stops[0]->stop_type,
            'nearest_distance_m' => round($stops[0]->distance_m),
            'stops_within_1km' => count($stops),
        ]
    );
}
```

### 4.5 Grocery Proximity Score

```php
private function scoreGrocery(float $lat, float $lng): ProximityFactor
{
    $maxDistance = 1000;

    $nearest = DB::selectOne("
        SELECT name,
               ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m
        FROM pois
        WHERE category = 'grocery'
          AND ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
        ORDER BY geom <-> ST_SetSRID(ST_MakePoint(?, ?), 4326)
        LIMIT 1
    ", [$lng, $lat, $lng, $lat, $maxDistance, $lng, $lat]);

    if (!$nearest) {
        return new ProximityFactor(slug: 'grocery', score: 0, details: []);
    }

    $decay = max(0, 1 - $nearest->distance_m / $maxDistance);

    return new ProximityFactor(
        slug: 'grocery',
        score: round($decay * 100),
        details: [
            'nearest_store' => $nearest->name,
            'distance_m' => round($nearest->distance_m),
        ]
    );
}
```

### 4.6 Negative POI Score

```php
private function scoreNegativePois(float $lat, float $lng): ProximityFactor
{
    $maxDistance = 500; // 500m — negative POIs only matter if very close

    $pois = DB::select("
        SELECT name, subcategory,
               ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m
        FROM pois
        WHERE signal = 'negative'
          AND ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
        ORDER BY distance_m
    ", [$lng, $lat, $lng, $lat, $maxDistance]);

    if (empty($pois)) {
        // No negative POIs nearby = full score (good)
        return new ProximityFactor(slug: 'negative_poi', score: 100, details: ['count' => 0]);
    }

    // Each nearby negative POI reduces the score, distance-weighted
    $penalty = 0;
    foreach ($pois as $poi) {
        $decay = max(0, 1 - $poi->distance_m / $maxDistance);
        $penalty += $decay * 20; // Each close negative POI costs up to 20 points
    }

    $score = max(0, 100 - $penalty);

    return new ProximityFactor(
        slug: 'negative_poi',
        score: round($score),
        details: [
            'count' => count($pois),
            'nearest' => $pois[0]->name ?? $pois[0]->subcategory,
            'nearest_distance_m' => round($pois[0]->distance_m),
        ]
    );
}
```

### 4.7 Positive POI Score

```php
private function scorePositivePois(float $lat, float $lng): ProximityFactor
{
    $maxDistance = 1000;

    $pois = DB::select("
        SELECT name, subcategory,
               ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m
        FROM pois
        WHERE signal = 'positive'
          AND ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
        ORDER BY distance_m
        LIMIT 20
    ", [$lng, $lat, $lng, $lat, $maxDistance]);

    if (empty($pois)) {
        return new ProximityFactor(slug: 'positive_poi', score: 0, details: ['count' => 0]);
    }

    // Each nearby positive POI adds to the score, distance-weighted, diminishing returns
    $bonus = 0;
    foreach ($pois as $i => $poi) {
        $decay = max(0, 1 - $poi->distance_m / $maxDistance);
        // Diminishing returns: first POI worth most, each subsequent worth less
        $diminishing = 1 / ($i + 1);
        $bonus += $decay * 15 * $diminishing;
    }

    $score = min(100, $bonus);

    return new ProximityFactor(
        slug: 'positive_poi',
        score: round($score),
        details: [
            'count' => count($pois),
            'types' => array_unique(array_column($pois, 'subcategory')),
        ]
    );
}
```

---

## Step 5: Blending Area + Proximity Scores

### 5.1 The Blended Score

In the `LocationController::show()` method (from the heatmap task), add proximity scoring:

```php
public function show(float $lat, float $lng)
{
    // ... existing code to get DeSO, area score, indicators, schools ...

    // NEW: compute proximity score
    $proximityService = app(ProximityScoreService::class);
    $proximity = $proximityService->score($lat, $lng);

    // Blend area + proximity
    $areaWeight = 0.70;
    $proximityWeight = 0.30;

    $areaScore = $score?->score ?? 50; // default to 50 if no area score
    $proximityScore = $proximity->compositeScore(); // 0-100

    $blendedScore = round(
        $areaScore * $areaWeight + $proximityScore * $proximityWeight,
        1
    );

    return response()->json([
        'location' => [ /* ... */ ],
        'score' => [
            'value' => $blendedScore,                    // ← This is now blended
            'area_score' => round($areaScore, 1),        // ← Show both components
            'proximity_score' => round($proximityScore, 1),
            'trend_1y' => $score?->trend_1y,
            'label' => $this->scoreLabel($blendedScore),
            'top_positive' => $score?->top_positive,
            'top_negative' => $score?->top_negative,
            'factor_scores' => $score?->factor_scores,
        ],
        'proximity' => $proximity->toArray(),            // ← Detailed breakdown
        'indicators' => [ /* ... */ ],
        'schools' => [ /* ... */ ],
    ]);
}
```

### 5.2 ProximityResult Class

```php
class ProximityResult
{
    public function __construct(
        public ProximityFactor $school,
        public ProximityFactor $greenSpace,
        public ProximityFactor $transit,
        public ProximityFactor $grocery,
        public ProximityFactor $negativePoi,
        public ProximityFactor $positivePoi,
    ) {}

    public function compositeScore(): float
    {
        // Weighted average of all proximity factors
        $weights = [
            'school' => 0.33,        // School is 10/30 of proximity budget
            'greenSpace' => 0.13,    // 4/30
            'transit' => 0.17,       // 5/30
            'grocery' => 0.10,       // 3/30
            'negativePoi' => 0.13,   // 4/30
            'positivePoi' => 0.13,   // 4/30
        ];

        $weighted = 0;
        $totalWeight = 0;
        foreach ($weights as $field => $weight) {
            $factor = $this->$field;
            if ($factor->score !== null) {
                $weighted += $factor->score * $weight;
                $totalWeight += $weight;
            }
        }

        return $totalWeight > 0 ? $weighted / $totalWeight : 50;
    }

    public function toArray(): array
    {
        return [
            'composite' => round($this->compositeScore(), 1),
            'factors' => [
                $this->school->toArray(),
                $this->greenSpace->toArray(),
                $this->transit->toArray(),
                $this->grocery->toArray(),
                $this->negativePoi->toArray(),
                $this->positivePoi->toArray(),
            ],
        ];
    }
}

class ProximityFactor
{
    public function __construct(
        public string $slug,
        public ?int $score,
        public array $details = [],
    ) {}

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'score' => $this->score,
            'details' => $this->details,
        ];
    }
}
```

---

## Step 6: Sidebar Display

### 6.1 Proximity Section in Sidebar

After the area indicator breakdown, add a proximity section:

```
│  ── Närhetsanalys ──────────────    │
│                                     │
│  🏫 Skola           ████████░░  82  │
│  Årstaskolan (241 mv)        200m   │
│                                     │
│  🌳 Grönområde      ██████████  97  │
│  Tantolunden                  120m  │
│                                     │
│  🚇 Kollektivtrafik ███████░░░  68  │
│  Zinkensdamm (T-bana)        350m   │
│                                     │
│  🛒 Livsmedel       █████████░  91  │
│  ICA Nära                     80m   │
│                                     │
│  ✓ Inga negativa POI inom 500m      │
│  ✓ 6 positiva POI inom 1 km        │
```

### 6.2 Score Breakdown in Header

The main score now shows a small indicator that it's blended:

```
│  ┌──────────────────────────┐       │
│  │     72                   │       │
│  │  Stabilt / Positivt      │       │
│  │  ↑ +3.2                  │       │
│  │                          │       │
│  │  Område: 68  Plats: 82   │       │
│  └──────────────────────────┘       │
```

"Område" (area) is the DeSO-level score. "Plats" (location) is the proximity score. The big number (72) is the blend. This tells the user: "the area is decent (68) but this specific spot is better than average for the area (82) because of what's nearby."

---

## Step 7: Performance

### 7.1 This Must Be Fast

The proximity score is computed on every pin drop. Target: **< 200ms** for all six queries combined.

**Why it should be fast:**
- Each query is a PostGIS spatial lookup within a small radius (500m-2km)
- With GIST spatial indexes on `geom` columns, these are index scans
- The POI table will have maybe 200,000 rows for all of Sweden (not big)
- Transit stops: ~50,000 rows
- Schools: ~10,000 rows
- Each query returns 1-20 rows max (LIMIT clauses)

**If it's slow:**
1. Make sure spatial indexes exist: `CREATE INDEX ON pois USING GIST (geom);`
2. Make sure `geography` casts are used (for meter-based distance, not degree-based)
3. Use the `<->` operator for KNN index scans (nearest-neighbor): `ORDER BY geom <-> point`
4. Consider running the six queries in parallel (PostgreSQL supports this natively via async, or use Laravel's `concurrently`)

### 7.2 Caching

For v1, don't cache. The queries are fast enough.

If needed later: cache proximity results per H3 resolution-10 cell (~15m precision). When a pin drops, round to nearest res-10 hex center, check cache. Nearby pins (within ~15m) get the same proximity score. Cache TTL: 24 hours or until POI data refreshes.

---

## Step 8: Admin — Proximity Weights

### 8.1 Store in Database

Add proximity indicators to the `indicators` table:

```php
// Seeder or migration
$proximityIndicators = [
    ['slug' => 'prox_school', 'name' => 'School Proximity & Quality', 'source' => 'proximity', 'unit' => 'score', 'direction' => 'positive', 'weight' => 0.10, 'category' => 'proximity'],
    ['slug' => 'prox_green_space', 'name' => 'Green Space Access', 'source' => 'proximity', 'unit' => 'score', 'direction' => 'positive', 'weight' => 0.04, 'category' => 'proximity'],
    ['slug' => 'prox_transit', 'name' => 'Transit Access', 'source' => 'proximity', 'unit' => 'score', 'direction' => 'positive', 'weight' => 0.05, 'category' => 'proximity'],
    ['slug' => 'prox_grocery', 'name' => 'Grocery Access', 'source' => 'proximity', 'unit' => 'score', 'direction' => 'positive', 'weight' => 0.03, 'category' => 'proximity'],
    ['slug' => 'prox_negative_poi', 'name' => 'Negative POI Proximity', 'source' => 'proximity', 'unit' => 'score', 'direction' => 'negative', 'weight' => 0.04, 'category' => 'proximity'],
    ['slug' => 'prox_positive_poi', 'name' => 'Positive POI Density', 'source' => 'proximity', 'unit' => 'score', 'direction' => 'positive', 'weight' => 0.04, 'category' => 'proximity'],
];
```

The `ProximityScoreService` should read weights from the `indicators` table, not hardcode them. Admin can tune proximity weights via the same indicator management page.

### 8.2 Weight Budget Update

| Category | Weight | Source |
|---|---|---|
| Income | 0.14 | SCB (area) |
| Employment | 0.07 | SCB (area) |
| Education (demographics) | 0.07 | SCB (area) |
| Education (school quality, area avg) | 0.17 | Skolverket (area) |
| Unallocated (crime, debt) | 0.25 | Future (area) |
| **Proximity: school** | **0.10** | Skolverket + PostGIS |
| **Proximity: green space** | **0.04** | OSM |
| **Proximity: transit** | **0.05** | GTFS/OSM |
| **Proximity: grocery** | **0.03** | OSM |
| **Proximity: negative POI** | **0.04** | OSM |
| **Proximity: positive POI** | **0.04** | OSM |
| **Total** | **1.00** | |

---

## Step 9: Heatmap Note

**The heatmap tiles still show area-level scores only.** Proximity is per-coordinate and can't be pre-rendered into tiles (there are infinite coordinates). The heatmap is the "big picture" — the sidebar score is the precise answer for the pinned location.

This means: the big number in the sidebar might differ slightly from the color on the map at that location. The color says "this area scores 68." The sidebar says "this exact spot scores 72." That's correct and expected — explain it in the UI with the "Område: 68 / Plats: 82" breakdown.

---

## Implementation Order

### Phase A: Database + Ingestion
1. Create `pois` and `transit_stops` migrations
2. Write Overpass API service for fetching OSM data
3. Write `ingest:osm-pois --category=parks` command
4. Write `ingest:osm-pois --category=grocery` command
5. Write `ingest:osm-pois --category=negative` command
6. Write `ingest:osm-pois --category=positive` command
7. Write `ingest:gtfs-stops` or `ingest:osm-pois --category=transit` command
8. Run all ingestion commands
9. Verify: `SELECT category, COUNT(*) FROM pois GROUP BY category;`

### Phase B: Proximity Scoring
10. Create `ProximityFactor` and `ProximityResult` classes
11. Create `ProximityScoreService` with all six scoring methods
12. Write a test command: `php artisan test:proximity --lat=59.334 --lng=18.065`
13. Verify: score makes sense for known locations (central Stockholm should score high on transit, high on grocery, depends on schools)
14. Test edge cases: rural Norrland (low on everything), coastal (no transit), etc.

### Phase C: API Integration
15. Add proximity scoring to `LocationController::show()`
16. Add blended score computation
17. Add proximity indicators to `indicators` table seeder
18. Verify API response includes both area and proximity data

### Phase D: Frontend
19. Add proximity section to sidebar
20. Update score header to show area/proximity breakdown
21. Add proximity indicator bars
22. Show nearest school/park/stop names and distances

---

## Verification

### Spot Checks

Test these specific locations and verify the proximity score makes sense:

| Location | Expected proximity | Why |
|---|---|---|
| Sveavägen, central Stockholm | Very high (80-95) | Transit everywhere, parks, grocery, schools |
| Danderyd centrum | High (75-90) | Great schools, green space, good transit |
| Rinkeby torg | Medium (50-65) | Transit decent, schools mediocre, limited positive POIs |
| Rural Norrland village | Low (20-40) | No transit, distant grocery, few POIs |
| Island in Stockholm archipelago | Very low (5-20) | Nothing nearby |

### Performance Check
- [ ] `php artisan test:proximity --lat=59.334 --lng=18.065` completes in < 200ms
- [ ] Pin drop → full sidebar (with proximity) loads in < 500ms total
- [ ] No noticeable delay compared to version without proximity

### Data Check
- [ ] `SELECT COUNT(*) FROM pois;` → expect 100,000-300,000
- [ ] `SELECT COUNT(*) FROM transit_stops;` → expect 30,000-60,000
- [ ] `SELECT category, COUNT(*) FROM pois GROUP BY category;` → all categories populated
- [ ] Spatial indexes exist on all geom columns

---

## What NOT To Do

- **DO NOT pre-compute proximity scores for all coordinates.** There are infinite coordinates. Compute on the fly per pin drop.
- **DO NOT pre-compute proximity scores per DeSO centroid and apply to the whole area.** That defeats the entire purpose — the point is that different locations within a DeSO get different scores.
- **DO NOT add proximity to the heatmap tiles.** Tiles show area scores only. Proximity is sidebar-only.
- **DO NOT make the API slow.** Six PostGIS queries with spatial indexes should be < 200ms total. If slow, check indexes first.
- **DO NOT weight proximity > 30% of the total score in v1.** Area-level data (income, crime, education) is more predictive of property values than proximity to a park. Proximity is the "micro-adjustment," not the main signal.
- **DO NOT import Google Places data in v1.** Start with OSM only (free, no API key). Add Google later for better commercial POI coverage.

**DO:**
- Use PostGIS `ST_DWithin` + GIST indexes for all spatial queries
- Use the `<->` KNN operator for nearest-neighbor lookups
- Read proximity weights from the indicators table (admin-tunable)
- Show both area and proximity scores in the sidebar (transparency)
- Show what's actually near the pin (school name, park name, stop name, distance)