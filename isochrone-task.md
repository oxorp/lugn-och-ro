# TASK: Isochrone Overlay — Replace Radius Circle with Real Walking-Time Polygons

## Context

The explore page currently draws a **crow-flies radius circle** around a dropped pin. This is misleading — a 1500m circle includes areas across highways you can't cross, ignores pedestrian bridges, and treats hilly terrain the same as flat roads. We're replacing it with **real isochrone polygons** computed from the actual street/path network.

This also upgrades the `ProximityScoreService` to use **actual walking/driving times** instead of straight-line distances when computing proximity scores. The isochrone polygons serve double duty: visual overlay on the map AND the scoring boundary for finding reachable POIs.

**What changes:**
- The dashed gray circle on the map becomes 3 layered isochrone polygons (5/10/15 min)
- `ProximityScoreService` queries POIs inside the isochrone polygon instead of `ST_DWithin(radius)`
- `LocationController` returns isochrone GeoJSON to the frontend
- Config switches from meters to minutes
- The report includes the isochrone visualization

**What stays the same:**
- DeSO-level area scoring (70% weight) — completely untouched
- Safety modulation logic — still applied, just on time instead of distance
- Category scoring structure — same factors, same weights
- School/POI/transit data — same tables, same queries, different spatial filter

---

## Step 1: Self-Host Valhalla Routing Engine

### 1.1 Why Valhalla (Not ORS API)

OpenRouteService's free API allows 500 isochrone requests/day. Every pin drop needs one isochrone call. That's 500 users/day max — too limiting even for launch. Self-hosting Valhalla gives us unlimited requests with <100ms latency.

Valhalla is Mapbox's open-source routing engine. It has a native `/isochrone` endpoint, runs on OSM data, and fits in ~2GB RAM for Sweden.

### 1.2 Add Valhalla to Docker

Add to `docker-compose.yml`:

```yaml
  valhalla:
    image: ghcr.io/gis-ops/docker-valhalla/valhalla:latest
    container_name: skapa-valhalla
    restart: unless-stopped
    ports:
      - "${VALHALLA_PORT:-8002}:8002"
    volumes:
      - skapa-valhalla-data:/custom_files
    environment:
      - tile_urls=https://download.geofabrik.de/europe/sweden-latest.osm.pbf
      - serve_tiles=True
      - build_elevation=False
      - build_admins=False
      - build_time_zones=False
      - force_rebuild=False
    networks:
      - skapa-network
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8002/status"]
      interval: 30s
      timeout: 10s
      retries: 5
```

Add volume:
```yaml
volumes:
  skapa-valhalla-data:
```

**First start takes 10-20 minutes** — it downloads Sweden's OSM extract (~1.2GB) and builds routing tiles. Subsequent starts are instant.

### 1.3 Verify Valhalla Is Running

After `docker compose up -d valhalla`, test:

```bash
curl -s "http://localhost:8002/isochrone?json=%7B%22locations%22%3A%5B%7B%22lat%22%3A59.3293%2C%22lon%22%3A18.0686%7D%5D%2C%22costing%22%3A%22pedestrian%22%2C%22contours%22%3A%5B%7B%22time%22%3A5%7D%2C%7B%22time%22%3A10%7D%2C%7B%22time%22%3A15%7D%5D%7D" | jq '.features | length'
# Should return: 3
```

### 1.4 Environment Config

Add to `.env`:
```
VALHALLA_URL=http://valhalla:8002
```

Add to `.env.example`:
```
VALHALLA_URL=http://valhalla:8002
```

---

## Step 2: IsochroneService

### 2.1 Create `app/Services/IsochroneService.php`

This service wraps Valhalla API calls and returns GeoJSON polygons.

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IsochroneService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('proximity.isochrone.valhalla_url', 'http://valhalla:8002');
    }

    /**
     * Generate isochrone polygons for a coordinate.
     *
     * Returns GeoJSON FeatureCollection with one polygon per contour interval.
     * Each feature has a `contour` property (minutes) and `area_km2`.
     *
     * Results are cached by ~100m grid cell (same as ProximityScoreService).
     *
     * @param float $lat
     * @param float $lng
     * @param string $costing  'pedestrian' | 'auto' | 'bicycle'
     * @param int[] $contours  Minutes for each ring, e.g. [5, 10, 15]
     * @return array{type: string, features: array}|null  GeoJSON FeatureCollection or null on failure
     */
    public function generate(
        float $lat,
        float $lng,
        string $costing = 'pedestrian',
        array $contours = [5, 10, 15],
    ): ?array {
        $gridLat = round($lat, 3);
        $gridLng = round($lng, 3);
        $contourKey = implode('-', $contours);
        $cacheKey = "isochrone:{$costing}:{$contourKey}:{$gridLat},{$gridLng}";

        return Cache::remember($cacheKey, 3600, function () use ($lat, $lng, $costing, $contours) {
            return $this->fetchFromValhalla($lat, $lng, $costing, $contours);
        });
    }

    /**
     * Get the outermost isochrone polygon as a WKT string for PostGIS queries.
     * This is the "reachable area" used to filter POIs.
     */
    public function outermostPolygonWkt(
        float $lat,
        float $lng,
        string $costing = 'pedestrian',
        int $maxMinutes = 15,
    ): ?string {
        $geojson = $this->generate($lat, $lng, $costing, [$maxMinutes]);

        if (!$geojson || empty($geojson['features'])) {
            return null;
        }

        // Valhalla returns polygons ordered outermost first
        $outermost = $geojson['features'][0];

        return $this->geojsonPolygonToWkt($outermost['geometry']);
    }

    private function fetchFromValhalla(float $lat, float $lng, string $costing, array $contours): ?array
    {
        $body = [
            'locations' => [['lat' => $lat, 'lon' => $lng]],
            'costing' => $costing,
            'contours' => array_map(fn ($min) => ['time' => $min], $contours),
            'polygons' => true,
            'generalize' => 50, // simplify geometry, meters tolerance
        ];

        try {
            $response = Http::timeout(5)
                ->post("{$this->baseUrl}/isochrone", $body);

            if (!$response->successful()) {
                Log::warning('Valhalla isochrone failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'lat' => $lat,
                    'lng' => $lng,
                ]);
                return null;
            }

            $geojson = $response->json();

            // Add area_km2 to each feature for display
            if (!empty($geojson['features'])) {
                foreach ($geojson['features'] as &$feature) {
                    $feature['properties']['area_km2'] = $this->estimateAreaKm2($feature['geometry']);
                }
            }

            return $geojson;
        } catch (\Throwable $e) {
            Log::error('Valhalla isochrone error', [
                'message' => $e->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
            ]);
            return null;
        }
    }

    /**
     * Convert GeoJSON Polygon to WKT for PostGIS ST_Contains queries.
     */
    private function geojsonPolygonToWkt(array $geometry): string
    {
        $type = $geometry['type'];
        $coords = $geometry['coordinates'];

        if ($type === 'Polygon') {
            $rings = [];
            foreach ($coords as $ring) {
                $points = array_map(fn ($p) => "{$p[0]} {$p[1]}", $ring);
                $rings[] = '(' . implode(', ', $points) . ')';
            }
            return 'POLYGON(' . implode(', ', $rings) . ')';
        }

        // MultiPolygon — take the largest
        if ($type === 'MultiPolygon') {
            // Use first polygon (typically the largest)
            $ring = $coords[0][0];
            $points = array_map(fn ($p) => "{$p[0]} {$p[1]}", $ring);
            return 'POLYGON((' . implode(', ', $points) . '))';
        }

        return '';
    }

    /**
     * Rough area estimate from polygon coordinates (Shoelace formula on lat/lng).
     */
    private function estimateAreaKm2(array $geometry): float
    {
        $coords = $geometry['coordinates'][0] ?? [];
        if (count($coords) < 3) return 0;

        $area = 0;
        $n = count($coords);
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $area += $coords[$i][0] * $coords[$j][1];
            $area -= $coords[$j][0] * $coords[$i][1];
        }
        $area = abs($area) / 2;

        // Convert degree² to km² at Swedish latitudes (~59°N)
        // 1° lng ≈ 56 km, 1° lat ≈ 111 km at 59°N
        return round($area * 56 * 111, 2);
    }
}
```

### 2.2 Fallback Strategy

If Valhalla is down or unreachable, fall back to the current crow-flies radius. The service returns `null`, and callers check:

```php
$isochrone = $this->isochrone->generate($lat, $lng);
if ($isochrone) {
    // Use isochrone polygon for POI queries
} else {
    // Fall back to ST_DWithin radius (current behavior)
}
```

This means the app never breaks if Valhalla is down — it just degrades to radius mode.

---

## Step 3: Update Config

### 3.1 Update `config/proximity.php`

Add isochrone config alongside the existing radius config (radius stays as fallback):

```php
    /*
    |--------------------------------------------------------------------------
    | Isochrone Configuration
    |--------------------------------------------------------------------------
    */

    'isochrone' => [
        'enabled' => env('ISOCHRONE_ENABLED', true),
        'valhalla_url' => env('VALHALLA_URL', 'http://valhalla:8002'),

        // Display contours shown on map (minutes)
        'display_contours' => [5, 10, 15],

        // Travel mode per urbanity tier
        'costing' => [
            'urban' => 'pedestrian',
            'semi_urban' => 'pedestrian',
            'rural' => 'auto',
        ],

        // Outermost contour used as scoring boundary per urbanity tier (minutes)
        // All POIs inside this isochrone are candidates for scoring
        'scoring_contour' => [
            'urban' => 15,
            'semi_urban' => 15,
            'rural' => 10,  // driving minutes in rural
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring Times (replaces scoring_radii when isochrone is enabled)
    |--------------------------------------------------------------------------
    |
    | Max travel time per category in minutes. Beyond this time,
    | the category contributes 0 to the proximity score.
    | These define the decay ceiling, not the isochrone boundary.
    |
    */

    'scoring_times' => [
        'school' => [
            'urban' => 15,
            'semi_urban' => 15,
            'rural' => 10,    // driving
        ],
        'green_space' => [
            'urban' => 10,
            'semi_urban' => 12,
            'rural' => 8,
        ],
        'transit' => [
            'urban' => 8,
            'semi_urban' => 10,
            'rural' => 8,
        ],
        'grocery' => [
            'urban' => 10,
            'semi_urban' => 12,
            'rural' => 8,
        ],
        'negative_poi' => [
            'urban' => 5,
            'semi_urban' => 5,
            'rural' => 5,
        ],
        'positive_poi' => [
            'urban' => 10,
            'semi_urban' => 10,
            'rural' => 8,
        ],
    ],
```

### 3.2 Add to `.env`

```
ISOCHRONE_ENABLED=true
VALHALLA_URL=http://valhalla:8002
```

---

## Step 4: Refactor ProximityScoreService

### 4.1 Core Change: Isochrone-Based POI Filtering

The key refactor is replacing `ST_DWithin(geom, point, radius_meters)` with `ST_Contains(isochrone_polygon, geom)`.

**Before:**
```sql
WHERE ST_DWithin(s.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
```

**After:**
```sql
WHERE ST_Contains(
    ST_SetSRID(ST_GeomFromText(?), 4326),
    s.geom
)
```

The `?` is the WKT string from `IsochroneService::outermostPolygonWkt()`.

### 4.2 Walking Time via Valhalla Matrix API

For scoring, we need the **actual walking time** from the pin to each POI, not just "is it inside the polygon." Valhalla's `/sources_to_targets` endpoint does this efficiently:

```
POST /sources_to_targets
{
  "sources": [{"lat": 59.3293, "lon": 18.0686}],
  "targets": [
    {"lat": 59.331, "lon": 18.071},
    {"lat": 59.328, "lon": 18.065},
    ...
  ],
  "costing": "pedestrian"
}
```

Returns travel time in seconds for each source→target pair in one call. This replaces the `ST_Distance` calculation.

### 4.3 Add Matrix Method to IsochroneService

```php
/**
 * Get walking/driving times from origin to multiple targets.
 *
 * @param float $lat Origin latitude
 * @param float $lng Origin longitude
 * @param array<array{lat: float, lng: float}> $targets
 * @param string $costing 'pedestrian' | 'auto'
 * @return array<int|null> Travel time in seconds per target, null if unreachable
 */
public function travelTimes(
    float $lat,
    float $lng,
    array $targets,
    string $costing = 'pedestrian',
): array {
    if (empty($targets)) {
        return [];
    }

    // Valhalla matrix has a limit of ~50 targets per call
    // Chunk if needed
    $allTimes = [];
    foreach (array_chunk($targets, 50) as $chunk) {
        $body = [
            'sources' => [['lat' => $lat, 'lon' => $lng]],
            'targets' => array_map(fn ($t) => ['lat' => $t['lat'], 'lon' => $t['lng']], $chunk),
            'costing' => $costing,
        ];

        try {
            $response = Http::timeout(5)
                ->post("{$this->baseUrl}/sources_to_targets", $body);

            if (!$response->successful()) {
                // Fall back: fill with nulls
                $allTimes = array_merge($allTimes, array_fill(0, count($chunk), null));
                continue;
            }

            $data = $response->json();
            $row = $data['sources_to_targets'][0] ?? [];

            foreach ($row as $entry) {
                $allTimes[] = ($entry['time'] ?? null) !== null
                    ? (int) $entry['time']
                    : null;
            }
        } catch (\Throwable $e) {
            $allTimes = array_merge($allTimes, array_fill(0, count($chunk), null));
        }
    }

    return $allTimes;
}
```

### 4.4 Refactored ProximityScoreService

The scoring flow becomes:

1. Generate outermost isochrone polygon (15 min walk / 10 min drive)
2. Query ALL scoreable POIs inside the isochrone polygon in one query
3. Get travel times to all found POIs in one Valhalla matrix call
4. Score each category using travel time instead of distance

**Update the `score()` method:**

```php
public function score(float $lat, float $lng): ProximityResult
{
    $deso = DB::selectOne('...');  // unchanged
    $urbanityTier = $deso->urbanity_tier ?? 'semi_urban';
    $safetyScore = $deso ? $this->safety->forDeso($deso->deso_code, now()->year - 1) : 0.5;

    $settings = $this->getCategorySettings();

    // Try isochrone-based scoring
    if (config('proximity.isochrone.enabled')) {
        $costing = config("proximity.isochrone.costing.{$urbanityTier}", 'pedestrian');
        $maxMinutes = config("proximity.isochrone.scoring_contour.{$urbanityTier}", 15);

        $boundaryWkt = $this->isochrone->outermostPolygonWkt($lat, $lng, $costing, $maxMinutes);

        if ($boundaryWkt) {
            return $this->scoreWithIsochrone(
                $lat, $lng, $boundaryWkt, $costing,
                $urbanityTier, $safetyScore, $settings,
            );
        }
    }

    // Fallback: radius-based scoring (current behavior)
    return $this->scoreWithRadius($lat, $lng, $urbanityTier, $safetyScore, $settings);
}
```

**The `scoreWithIsochrone` method:**

```php
private function scoreWithIsochrone(
    float $lat, float $lng,
    string $boundaryWkt, string $costing,
    string $urbanityTier, float $safetyScore,
    Collection $settings,
): ProximityResult {
    // 1. Query ALL POIs + schools inside the isochrone boundary in ONE query
    $pois = $this->queryPoisInPolygon($lat, $lng, $boundaryWkt);
    $schools = $this->querySchoolsInPolygon($lat, $lng, $boundaryWkt);
    $transitStops = $this->queryTransitInPolygon($lat, $lng, $boundaryWkt);

    // 2. Collect all target coordinates for matrix call
    $targets = [];
    $targetMap = []; // maps index → {type, original_index}

    foreach ($schools as $i => $s) {
        $targets[] = ['lat' => (float)$s->lat, 'lng' => (float)$s->lng];
        $targetMap[] = ['type' => 'school', 'index' => $i];
    }
    foreach ($pois as $i => $p) {
        $targets[] = ['lat' => (float)$p->lat, 'lng' => (float)$p->lng];
        $targetMap[] = ['type' => 'poi', 'index' => $i];
    }
    foreach ($transitStops as $i => $t) {
        $targets[] = ['lat' => (float)$t->lat, 'lng' => (float)$t->lng];
        $targetMap[] = ['type' => 'transit', 'index' => $i];
    }

    // 3. Get actual travel times in ONE matrix call
    $travelTimes = $this->isochrone->travelTimes($lat, $lng, $targets, $costing);

    // 4. Attach travel times back to the source objects
    foreach ($travelTimes as $idx => $seconds) {
        $map = $targetMap[$idx];
        match ($map['type']) {
            'school' => $schools[$map['index']]->travel_seconds = $seconds,
            'poi' => $pois[$map['index']]->travel_seconds = $seconds,
            'transit' => $transitStops[$map['index']]->travel_seconds = $seconds,
        };
    }

    // 5. Score each category using travel time
    return new ProximityResult(
        school: $this->scoreSchoolByTime($schools, $urbanityTier, $safetyScore, $settings),
        greenSpace: $this->scoreCategoryByTime($pois, 'green_space', ['park', 'nature_reserve'], $urbanityTier, $safetyScore, $settings),
        transit: $this->scoreTransitByTime($transitStops, $urbanityTier, $safetyScore, $settings),
        grocery: $this->scoreCategoryByTime($pois, 'grocery', ['grocery'], $urbanityTier, $safetyScore, $settings),
        negativePoi: $this->scoreNegativeByTime($pois, $urbanityTier),
        positivePoi: $this->scorePositiveByTime($pois, $urbanityTier, $safetyScore, $settings),
        safetyScore: $safetyScore,
        urbanityTier: $urbanityTier,
    );
}
```

### 4.5 Time-Based Decay

Replace distance decay with time decay. The logic is identical, just the unit changes:

```php
private function decayWithSafetyTime(
    ?int $travelSeconds,
    float $maxMinutes,
    float $safetyScore,
    float $safetySensitivity,
): float {
    if ($travelSeconds === null) return 0.0;

    $travelMinutes = $travelSeconds / 60.0;
    $riskPenalty = (1.0 - $safetyScore) * $safetySensitivity;
    $effectiveMinutes = $travelMinutes * (1.0 + $riskPenalty);

    return max(0.0, 1.0 - $effectiveMinutes / $maxMinutes);
}
```

The `maxMinutes` comes from `config('proximity.scoring_times.school.urban')` etc.

### 4.6 `getMaxMinutes` Helper

```php
private function getMaxMinutes(string $category, string $urbanityTier): float
{
    $times = config("proximity.scoring_times.{$category}");
    if (is_array($times)) {
        return (float) ($times[$urbanityTier] ?? $times['semi_urban'] ?? 10);
    }
    return (float) $times;
}
```

### 4.7 Spatial Queries (Inside Polygon)

Replace `ST_DWithin` queries with `ST_Contains`:

```php
private function querySchoolsInPolygon(float $lat, float $lng, string $boundaryWkt): array
{
    return DB::select("
        SELECT s.name, s.school_unit_code, s.type_of_schooling, s.operator_type,
               s.lat, s.lng,
               ss.merit_value_17, ss.goal_achievement_pct,
               ss.teacher_certification_pct, ss.student_count,
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
          AND ST_Contains(
              ST_SetSRID(ST_GeomFromText(?), 4326),
              s.geom
          )
        ORDER BY distance_m
        LIMIT 15
    ", [$lng, $lat, $boundaryWkt]);
}
```

Same pattern for `queryPoisInPolygon` and `queryTransitInPolygon`. Keep `distance_m` in the query — it's still useful for display ("340m away") even though scoring uses travel time.

### 4.8 Keep `scoreWithRadius` as Fallback

Rename all current scoring methods with a `ByRadius` suffix. When isochrone is unavailable, call these. Do NOT delete them.

```php
// Current method becomes:
private function scoreSchoolByRadius(...) { /* existing code */ }

// New method:
private function scoreSchoolByTime(...) { /* time-based version */ }
```

### 4.9 Update ProximityFactor Details

Add `travel_seconds` and `travel_minutes` to the factor details:

```php
return new ProximityFactor(
    slug: 'prox_school',
    score: (int) round($bestScore * 100),
    details: [
        'nearest_school' => $bestSchool->name,
        'nearest_merit' => (float) $bestSchool->merit_value_17,
        'nearest_distance_m' => (int) round($bestSchool->distance_m),
        'travel_seconds' => $bestSchool->travel_seconds,
        'travel_minutes' => round($bestSchool->travel_seconds / 60, 1),
        'effective_minutes' => round($effectiveMinutes, 1),
        'scoring_mode' => 'isochrone',
        'costing' => $costing,
        'schools_found' => count($schools),
        'schools' => $schoolDetails,
    ],
);
```

---

## Step 5: Update LocationController

### 5.1 Return Isochrone GeoJSON

The `LocationController::show()` method currently returns `display_radius`. Add `isochrone` to the response:

```php
// After computing proximity score, generate display isochrone
$isochrone = null;
if (config('proximity.isochrone.enabled')) {
    $costing = config("proximity.isochrone.costing.{$urbanityTier}", 'pedestrian');
    $contours = config('proximity.isochrone.display_contours', [5, 10, 15]);

    $isoService = app(IsochroneService::class);
    $isochrone = $isoService->generate($lat, $lng, $costing, $contours);
}
```

Add to the JSON response:

```php
return response()->json([
    'location' => [...],
    'score' => $scoreData,
    'display_radius' => $displayRadius,  // keep for fallback
    'isochrone' => $isochrone,            // NEW: GeoJSON FeatureCollection or null
    'isochrone_mode' => $isochrone ? config("proximity.isochrone.costing.{$urbanityTier}") : null,
    'proximity' => $proximity->toArray(),
    // ... rest unchanged
]);
```

### 5.2 Update LocationData TypeScript Type

In `resources/js/pages/explore/types.ts`, add:

```typescript
export interface IsochroneFeature {
    type: 'Feature';
    geometry: {
        type: 'Polygon' | 'MultiPolygon';
        coordinates: number[][][] | number[][][][];
    };
    properties: {
        contour: number;      // minutes
        area_km2: number;
        color?: string;
        opacity?: number;
    };
}

export interface IsochroneData {
    type: 'FeatureCollection';
    features: IsochroneFeature[];
}
```

Add to `LocationData`:

```typescript
export interface LocationData {
    // ... existing fields ...
    isochrone: IsochroneData | null;
    isochrone_mode: 'pedestrian' | 'auto' | 'bicycle' | null;
}
```

---

## Step 6: Frontend — Isochrone Map Layer

### 6.1 Replace Radius Circle with Isochrone Polygons

In `deso-map.tsx`, replace `setRadiusCircle` with `setIsochrone`:

**Update `HeatmapMapHandle` interface:**

```typescript
export interface HeatmapMapHandle {
    // ... existing methods ...
    setRadiusCircle: (lat: number, lng: number, radiusMeters: number) => void; // KEEP for fallback
    setIsochrone: (geojson: IsochroneData) => void;  // NEW
    clearIsochrone: () => void;                        // NEW
}
```

**Add isochrone source and layer refs:**

```typescript
const isochroneSourceRef = useRef<VectorSource | null>(null);
const isochroneLayerRef = useRef<VectorLayer | null>(null);
```

**Create the isochrone layer during map init** (zIndex 4, same as current radius layer):

```typescript
// Isochrone layer — replaces radius circle
const isochroneSource = new VectorSource();
isochroneSourceRef.current = isochroneSource;
const isochroneLayer = new VectorLayer({
    source: isochroneSource,
    zIndex: 4,
    style: (feature) => {
        const contour = feature.get('contour');
        // 5 min = most opaque, 15 min = least opaque
        const opacity = contour === 5 ? 0.15 : contour === 10 ? 0.08 : 0.04;
        const strokeOpacity = contour === 5 ? 0.6 : contour === 10 ? 0.4 : 0.25;

        return new Style({
            fill: new Fill({
                color: `rgba(59, 130, 246, ${opacity})`,  // blue tint
            }),
            stroke: new Stroke({
                color: `rgba(59, 130, 246, ${strokeOpacity})`,
                width: contour === 5 ? 2 : 1.5,
                lineDash: contour === 15 ? [6, 4] : undefined,
            }),
        });
    },
});
```

**Implement `setIsochrone`:**

```typescript
setIsochrone(geojson: IsochroneData) {
    const source = isochroneSourceRef.current;
    if (!source) return;
    source.clear();

    // Also clear old radius circle
    radiusSourceRef.current?.clear();

    const format = new GeoJSON();
    const features = format.readFeatures(geojson, {
        featureProjection: 'EPSG:3857',
    });

    source.addFeatures(features);
},
clearIsochrone() {
    isochroneSourceRef.current?.clear();
},
```

### 6.2 Update `use-location-data.ts`

In `fetchLocationData`, replace the radius circle call with isochrone:

```typescript
// Replace this:
mapRef.current?.setRadiusCircle(lat, lng, data.display_radius);

// With this:
if (data.isochrone) {
    mapRef.current?.setIsochrone(data.isochrone);
} else {
    // Fallback: show radius circle if no isochrone available
    mapRef.current?.setRadiusCircle(lat, lng, data.display_radius);
}
```

Update `clearLocation`:

```typescript
const clearLocation = useCallback(() => {
    setPinActive(false);
    setLocationData(null);
    setLocationName(null);
    mapRef.current?.clearSchoolMarkers();
    mapRef.current?.clearPoiMarkers();
    mapRef.current?.clearIsochrone();       // NEW
    window.history.pushState(null, '', '/');
}, [mapRef]);
```

### 6.3 Isochrone Legend Labels

When isochrone is active, show walking/driving time labels on the map. Add small labels at the edge of each contour:

```typescript
// After setting isochrone features, add label overlays
if (data.isochrone_mode === 'pedestrian') {
    // "5 min walk", "10 min walk", "15 min walk"
} else {
    // "5 min drive", "10 min drive", "10 min drive"
}
```

Implementation: Create small OpenLayers Overlay elements positioned at the rightmost point of each isochrone polygon edge. Use subtle text styling (10px, muted color).

---

## Step 7: Sidebar Updates

### 7.1 Show Travel Time Instead of Distance

In `proximity-factor-row.tsx`, update the display to show walking time alongside distance:

**Before:**
```
🏫 Närmaste grundskola: Årstaskolan (340m)
```

**After:**
```
🏫 Närmaste grundskola: Årstaskolan (5 min promenad · 340m)
```

Check `details.scoring_mode === 'isochrone'` to decide which format to show:

```typescript
const travelLabel = details.scoring_mode === 'isochrone' && details.travel_minutes
    ? `${details.travel_minutes} min ${mode === 'pedestrian' ? 'promenad' : 'bil'} · ${details.nearest_distance_m}m`
    : `${details.nearest_distance_m}m`;
```

### 7.2 School List Travel Time

In the school cards, add travel time:

```
📚 Årstaskolan
   Grundskola · Kommunal
   Meritvärde: 241 · 5 min promenad
```

### 7.3 POI Summary Travel Time

In the POI summary section, show time instead of (or alongside) distance:

```
🛒 Mataffär: ICA Kvantum (4 min promenad · 280m)
🌳 Park: Tantolunden (7 min promenad · 520m)
🚌 Busshållplats: Hornstull (3 min promenad · 180m)
```

---

## Step 8: Report Integration

### 8.1 Include Isochrone in Report Snapshot

In `ReportGenerationService::generate()`, generate and store the isochrone GeoJSON:

```php
// After proximity factors
$isoService = app(IsochroneService::class);
$costing = config("proximity.isochrone.costing.{$urbanityTier}", 'pedestrian');
$isochrone = $isoService->generate((float) $report->lat, (float) $report->lng, $costing);

$report->update([
    // ... existing fields ...
    'isochrone' => $isochrone,
    'isochrone_mode' => $costing,
]);
```

Add `isochrone` and `isochrone_mode` columns to the `reports` table:

```php
$table->json('isochrone')->nullable();
$table->string('isochrone_mode', 20)->nullable();
```

### 8.2 Report Map Visualization

When rendering the report's static map snapshot, overlay the isochrone polygons instead of the radius circle. The exact implementation depends on how the report PDF currently renders the map (likely a static image from tile server + overlays). Add the isochrone as a GeoJSON polygon overlay rendered on the static map.

---

## Step 9: Testing & Verification

### 9.1 Verify Valhalla

```bash
# Inside Docker
docker exec skapa-app curl -s "http://valhalla:8002/status" | jq .

# Test isochrone generation (Stockholm Central)
docker exec skapa-app curl -s "http://valhalla:8002/isochrone" \
  -d '{"locations":[{"lat":59.3293,"lon":18.0686}],"costing":"pedestrian","contours":[{"time":5},{"time":10},{"time":15}],"polygons":true}' \
  | jq '.features | length'
# Expect: 3

# Test matrix API
docker exec skapa-app curl -s "http://valhalla:8002/sources_to_targets" \
  -d '{"sources":[{"lat":59.3293,"lon":18.0686}],"targets":[{"lat":59.331,"lon":18.071}],"costing":"pedestrian"}' \
  | jq '.sources_to_targets[0][0].time'
# Expect: some number of seconds
```

### 9.2 Verify IsochroneService

```bash
docker exec skapa-app php artisan tinker
>>> app(App\Services\IsochroneService::class)->generate(59.3293, 18.0686)
# Should return GeoJSON with 3 features

>>> app(App\Services\IsochroneService::class)->outermostPolygonWkt(59.3293, 18.0686)
# Should return WKT string starting with "POLYGON(("
```

### 9.3 Compare Scoring

Test the same location with both modes and compare:

```bash
docker exec skapa-app php artisan tinker

# Isochrone mode
>>> config(['proximity.isochrone.enabled' => true]);
>>> $iso = app(App\Services\ProximityScoreService::class)->score(59.3293, 18.0686);
>>> $iso->compositeScore()

# Radius mode
>>> config(['proximity.isochrone.enabled' => false]);
>>> $rad = app(App\Services\ProximityScoreService::class)->score(59.3293, 18.0686);
>>> $rad->compositeScore()
```

Scores should differ — isochrone-based will be more accurate because it accounts for actual walkability. Verify:
- A location near a highway shows different reachability
- A location with pedestrian bridges/underpasses includes POIs the radius would miss
- Rural locations use driving mode and cover a larger area

### 9.4 Visual Checklist

- [ ] Pin drop shows 3 layered blue polygons instead of a dashed gray circle
- [ ] 5-min polygon is most opaque (inner), 15-min is faintest (outer)
- [ ] Polygons follow actual streets (not circular)
- [ ] Time labels appear at polygon edges ("5 min promenad")
- [ ] POI markers appear inside the isochrone, not outside
- [ ] School markers appear inside the isochrone, not outside
- [ ] Sidebar shows travel time: "5 min promenad · 340m"
- [ ] Clicking a different location clears old isochrone, shows new one
- [ ] Clearing pin clears isochrone
- [ ] Rural locations show larger isochrone polygons (driving mode)
- [ ] If Valhalla is down, falls back to radius circle (no crash)
- [ ] Report PDF includes isochrone overlay on map snapshot
- [ ] Score values are slightly different from radius mode (expected)

### 9.5 Performance Check

- [ ] Isochrone generation: <200ms (Valhalla local)
- [ ] Matrix API call: <100ms for up to 50 targets
- [ ] Total pin-drop response time: <500ms (was ~200ms with radius)
- [ ] Cache hit: <10ms (same grid cell within 1 hour)

---

## Notes for the Agent

### Valhalla API Reference

**Isochrone:**
```
POST /isochrone
{
  "locations": [{"lat": 59.33, "lon": 18.07}],
  "costing": "pedestrian",        // or "auto", "bicycle"
  "contours": [{"time": 5}, {"time": 10}, {"time": 15}],
  "polygons": true,
  "generalize": 50                // simplify in meters
}
→ GeoJSON FeatureCollection with polygon per contour
```

**Matrix (sources_to_targets):**
```
POST /sources_to_targets
{
  "sources": [{"lat": 59.33, "lon": 18.07}],
  "targets": [{"lat": 59.34, "lon": 18.08}, ...],
  "costing": "pedestrian"
}
→ {"sources_to_targets": [[{"time": 342, "distance": 456}, ...]]}
```

**Status:**
```
GET /status → {"version": "...", "tileset_last_modified": ...}
```

### The generalize Parameter

`"generalize": 50` simplifies isochrone polygons to ~50m tolerance. Without this, polygons have thousands of vertices (every street segment). With it, they're smooth enough for display but still follow the road network. Increase to 100-150 if polygon rendering feels sluggish.

### Valhalla Docker Image

The `ghcr.io/gis-ops/docker-valhalla/valhalla:latest` image auto-downloads OSM data on first run. Set `tile_urls` to the Geofabrik Sweden extract. Set `force_rebuild=False` so it only builds tiles once.

If tiles need rebuilding (e.g., OSM data is stale), set `force_rebuild=True`, restart the container, wait 15-20 min, then set it back to `False`.

### Cache Strategy

Isochrone results are cached by ~100m grid cell (same as proximity scores). This means:
- Two pin drops 50m apart share the same cached isochrone
- Cache TTL: 1 hour (same as proximity cache)
- Cache key includes costing mode and contour intervals

The matrix API results are NOT cached separately — they're part of the proximity score computation which is already cached as a whole.

### What NOT to Do

- Don't remove the radius fallback — Valhalla might be down
- Don't call Valhalla for DeSO-level scoring — isochrones are pin-level only
- Don't use the ORS hosted API — rate limits are too restrictive
- Don't generate isochrones for the heatmap tile rendering — those use pre-computed DeSO scores
- Don't increase `generalize` above 200 — polygons become too smooth and lose the "follows streets" quality
- Don't cache matrix results separately — they're only useful in the context of a specific proximity score computation

### What to Prioritize

1. Get Valhalla running in Docker and verify the API works
2. Build IsochroneService with isochrone + matrix methods
3. Get the isochrone polygons rendering on the map (visual first)
4. Then wire up the scoring refactor (ProximityScoreService)
5. Sidebar time labels are polish — do after scoring works
6. Report integration is last — depends on how the map snapshot is currently rendered
