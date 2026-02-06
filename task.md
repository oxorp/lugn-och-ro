# TASK: H3 Hexagonal Grid + Spatial Smoothing

## Context

The map currently renders DeSO polygons — irregular administrative boundaries drawn by SCB along streets, railways, and municipal lines. This creates a visual and analytical problem: two DeSOs can sit side by side with dramatically different scores, separated by an arbitrary line that doesn't reflect how neighborhoods actually work. A deep purple DeSO next to a dark green one looks jarring and undermines trust.

This task implements the H3 hexagonal grid system that was always planned in the architecture (see `data_pipeline_specification.md` §2.2–2.4). It replaces the irregular DeSO polygons with a uniform hexagonal grid as the **primary visual layer**, adds spatial smoothing so scores transition gradually across boundaries, and introduces layer controls so users can switch between views.

**Why now:** Every new data source and UI feature we build assumes a rendering unit. If that unit stays DeSO-only, switching to H3 later means rewriting the frontend layer system, the score API, the GeoJSON endpoint, and every sidebar interaction. The longer we wait, the more code we rewrite. The data architecture (indicators, normalization, scoring) doesn't change — only the spatial unit that scores get projected onto.

## Goals

1. Install `h3-pg` extension in PostgreSQL and build the DeSO→H3 mapping table
2. Project all existing indicator scores onto H3 resolution-8 cells
3. Implement spatial smoothing with configurable intensity
4. Render H3 hexagons as the default map layer in OpenLayers
5. Add a layer control: H3 hexagons (default) / DeSO polygons (toggle)
6. Add a smoothing control (admin/research only): Raw / Smoothed (toggle)
7. Ensure the sidebar, school markers, and all click interactions work with both layers

---

## Step 1: Install h3-pg Extension

### 1.1 Docker Setup

The `h3-pg` extension needs to be compiled and installed in the PostgreSQL container. Update the Docker setup:

**Option A: Build from source in Dockerfile**

```dockerfile
# In the PostgreSQL Dockerfile or docker-compose build step
RUN apt-get update && apt-get install -y \
    postgresql-server-dev-16 \
    cmake \
    build-essential \
    git \
  && git clone https://github.com/zachasme/h3-pg.git /tmp/h3-pg \
  && cd /tmp/h3-pg \
  && cmake -B build -DCMAKE_BUILD_TYPE=Release \
  && cmake --build build \
  && cmake --install build --component h3-pg \
  && rm -rf /tmp/h3-pg \
  && apt-get purge -y cmake build-essential git \
  && apt-get autoremove -y \
  && rm -rf /var/lib/apt/lists/*
```

**Option B: Use pgxn**

```dockerfile
RUN apt-get update && apt-get install -y \
    pip postgresql-server-dev-16 cmake build-essential \
  && pip install pgxnclient \
  && pgxn install h3 \
  && apt-get purge -y cmake build-essential \
  && rm -rf /var/lib/apt/lists/*
```

**Option C: Use a pre-built image**

Check if a `postgis/postgis` variant with h3 exists, or build a custom image.

### 1.2 Enable Extensions

Migration:

```php
public function up(): void
{
    DB::statement('CREATE EXTENSION IF NOT EXISTS h3');
    DB::statement('CREATE EXTENSION IF NOT EXISTS h3_postgis CASCADE');
}
```

### 1.3 Verify Installation

```sql
-- Should return a valid H3 index
SELECT h3_latlng_to_cell(POINT(59.3293, 18.0686), 8);
-- Stockholm → something like '881f1d4a7ffffff'

-- Should return hex boundary as geometry
SELECT h3_cell_to_boundary_geometry(h3_latlng_to_cell(POINT(59.3293, 18.0686), 8));
```

---

## Step 2: Database — H3 Tables

### 2.1 DeSO-to-H3 Mapping Table

This is the core lookup table. Pre-computed once, reused for every data projection.

```php
Schema::create('deso_h3_mapping', function (Blueprint $table) {
    $table->id();
    $table->string('deso_code', 10)->index();
    $table->string('h3_index', 16)->index();  // H3 index as hex string (15-16 chars at res 8)
    $table->decimal('area_weight', 8, 6);      // Fraction of this hex inside this DeSO (0.0–1.0)
    $table->integer('resolution')->default(8);
    $table->timestamps();

    $table->unique(['deso_code', 'h3_index']);
    $table->index(['h3_index', 'deso_code']);   // For reverse lookups
});
```

**`area_weight` explained:**
- A hex entirely inside one DeSO: `area_weight = 1.0`
- A hex split 60/40 between two DeSOs: two rows, weights `0.6` and `0.4`
- Weights for a given `h3_index` should sum to ~1.0 (minor rounding is fine)

### 2.2 H3 Scores Table

Pre-computed scores at H3 level, analogous to `composite_scores` but per hex.

```php
Schema::create('h3_scores', function (Blueprint $table) {
    $table->id();
    $table->string('h3_index', 16)->index();
    $table->integer('year');
    $table->integer('resolution')->default(8);
    $table->decimal('score_raw', 6, 2)->nullable();      // Unsmoothed score (projected from DeSO)
    $table->decimal('score_smoothed', 6, 2)->nullable();  // After spatial smoothing
    $table->decimal('smoothing_factor', 4, 3)->default(0.300);  // The factor used
    $table->decimal('trend_1y', 6, 2)->nullable();
    $table->json('factor_scores')->nullable();
    $table->string('primary_deso_code', 10)->nullable();  // DeSO with largest area_weight for this hex
    $table->timestamp('computed_at');
    $table->timestamps();

    $table->unique(['h3_index', 'year', 'resolution']);
});
```

### 2.3 Smoothing Configuration Table

```php
Schema::create('smoothing_configs', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->decimal('self_weight', 4, 3);       // Weight on the hex's own score (e.g., 0.700)
    $table->decimal('neighbor_weight', 4, 3);    // Total weight distributed to neighbors (e.g., 0.300)
    $table->integer('k_rings')->default(1);       // How many rings of neighbors to include
    $table->string('decay_function', 20)->default('linear');  // 'linear', 'gaussian', 'uniform'
    $table->boolean('is_active')->default(false);
    $table->timestamps();
});
```

Seed with default configs:

| name | self_weight | neighbor_weight | k_rings | decay_function |
|------|------------|----------------|---------|----------------|
| None (raw) | 1.000 | 0.000 | 0 | linear |
| Light | 0.700 | 0.300 | 1 | linear |
| Medium | 0.600 | 0.400 | 1 | linear |
| Strong | 0.500 | 0.500 | 2 | gaussian |

---

## Step 3: Build the DeSO→H3 Mapping

### 3.1 Artisan Command

```bash
php artisan build:deso-h3-mapping [--resolution=8]
```

This is the most computationally intensive step and only needs to run once (or when DeSO boundaries are updated by SCB).

### 3.2 Algorithm

For each DeSO polygon in `deso_areas`:

1. Use `h3_polygon_to_cells()` to find all H3 cells whose **centroids** fall within the polygon
2. For boundary cells (cells that overlap multiple DeSOs), compute the area weight

**Approach A: Centroid-based (fast, good enough)**

```sql
-- For each DeSO, get all H3 cells whose centroids fall inside
INSERT INTO deso_h3_mapping (deso_code, h3_index, area_weight, resolution)
SELECT
    d.deso_code,
    h3_polygon_to_cells(d.geom, 8) AS h3_index,
    1.0 AS area_weight,  -- Centroid is inside → assign fully
    8 AS resolution
FROM deso_areas d;
```

The `h3_polygon_to_cells` function returns cells whose centroids are contained within the polygon. Since DeSOs partition Sweden completely (no gaps, no overlaps), every hex centroid falls in exactly one DeSO. This means:
- Every hex maps to exactly one DeSO with weight 1.0
- No boundary splitting needed
- This is a clean partition, not an approximation

**This is actually the recommended approach.** H3 documentation states: "Containment is determined by centroids of the cells, so that a partitioning of polygons (covering an area without overlaps) will result in a partitioning of H3 cells."

Since DeSOs are a complete partition of Sweden, centroid-based assignment is exact and complete. No area weighting needed.

**Approach B: Area-weighted (more accurate, slower)**

If you want boundary hexes to blend between DeSOs (which the smoothing will handle anyway), compute actual intersection areas:

```sql
-- For each H3 cell, find all DeSOs it overlaps and compute area fractions
WITH hex_boundaries AS (
    SELECT
        h.h3_index,
        h3_cell_to_boundary_geometry(h.h3_index) AS hex_geom
    FROM (
        SELECT DISTINCT h3_polygon_to_cells(geom, 8) AS h3_index
        FROM deso_areas
    ) h
)
INSERT INTO deso_h3_mapping (deso_code, h3_index, area_weight, resolution)
SELECT
    d.deso_code,
    hb.h3_index,
    ST_Area(ST_Intersection(d.geom, hb.hex_geom)) / ST_Area(hb.hex_geom) AS area_weight,
    8 AS resolution
FROM hex_boundaries hb
JOIN deso_areas d ON ST_Intersects(d.geom, hb.hex_geom)
WHERE ST_Area(ST_Intersection(d.geom, hb.hex_geom)) / ST_Area(hb.hex_geom) > 0.01;  -- Skip trivial overlaps
```

**Recommendation:** Start with Approach A. The spatial smoothing (Step 5) handles the boundary blending more elegantly than area weighting does. Area weighting only matters for the literal edge hexes; smoothing affects all hexes near boundaries. Use Approach A and let smoothing do the heavy lifting.

### 3.3 Expected Numbers

Sweden's total area is ~450,000 km². At resolution 8 (~0.74 km² per hex):
- **~600,000 hexagons** covering all of Sweden
- Each of the 6,160 DeSOs maps to roughly **2-15 hexes** in urban areas, **50-500+** in rural areas
- The mapping table will have ~600,000 rows (one per hex if using centroid approach)

**Performance note:** `h3_polygon_to_cells` on a large rural DeSO polygon could return thousands of cells. Process DeSOs in batches and commit periodically. The total build time should be 5-30 minutes depending on hardware.

### 3.4 Verification

```sql
-- Total hexes covering Sweden
SELECT COUNT(*) FROM deso_h3_mapping;
-- Expect: ~500,000–700,000

-- Every DeSO should have at least one hex
SELECT COUNT(DISTINCT deso_code) FROM deso_h3_mapping;
-- Expect: 6,160 (all DeSOs)

-- Distribution of hexes per DeSO
SELECT
    CASE
        WHEN cnt <= 5 THEN '1-5'
        WHEN cnt <= 20 THEN '6-20'
        WHEN cnt <= 100 THEN '21-100'
        ELSE '100+'
    END AS hex_range,
    COUNT(*) AS deso_count
FROM (
    SELECT deso_code, COUNT(*) AS cnt
    FROM deso_h3_mapping
    GROUP BY deso_code
) sub
GROUP BY 1 ORDER BY 1;
```

---

## Step 4: Project Scores to H3

### 4.1 Artisan Command

```bash
php artisan project:scores-to-h3 [--year=2024] [--resolution=8]
```

### 4.2 Score Projection Logic

For each H3 cell, its raw score comes from its DeSO's composite score:

**Centroid approach (Approach A from Step 3):**
```sql
INSERT INTO h3_scores (h3_index, year, resolution, score_raw, primary_deso_code, computed_at)
SELECT
    m.h3_index,
    cs.year,
    8,
    cs.score,
    m.deso_code,
    NOW()
FROM deso_h3_mapping m
JOIN composite_scores cs ON cs.deso_code = m.deso_code
WHERE cs.year = ?;
```

Simple and fast — each hex inherits its parent DeSO's score directly.

**Area-weighted approach (Approach B):**
```sql
INSERT INTO h3_scores (h3_index, year, resolution, score_raw, primary_deso_code, computed_at)
SELECT
    m.h3_index,
    cs.year,
    8,
    SUM(cs.score * m.area_weight) AS score_raw,
    (SELECT deso_code FROM deso_h3_mapping WHERE h3_index = m.h3_index ORDER BY area_weight DESC LIMIT 1),
    NOW()
FROM deso_h3_mapping m
JOIN composite_scores cs ON cs.deso_code = m.deso_code
WHERE cs.year = ?
GROUP BY m.h3_index, cs.year;
```

Boundary hexes get blended scores. Interior hexes are unaffected.

### 4.3 Also Project Factor Scores

For the sidebar to work, each hex needs factor breakdowns too. Copy `factor_scores` JSON from the DeSO's `composite_scores`:

```sql
UPDATE h3_scores hs
SET factor_scores = cs.factor_scores,
    trend_1y = cs.trend_1y
FROM composite_scores cs
WHERE cs.deso_code = hs.primary_deso_code
  AND cs.year = hs.year;
```

---

## Step 5: Spatial Smoothing

### 5.1 The Math

Spatial smoothing replaces each hex's score with a weighted average of itself and its neighbors. Using H3's `h3_grid_disk` function:

```
smoothed(hex) = self_weight × score(hex) + neighbor_weight × Σ(decay(d) × score(neighbor)) / Σ(decay(d))
```

Where:
- `self_weight + neighbor_weight = 1.0`
- `decay(d)` depends on the ring distance and decay function
- For k=1 with linear decay: all 6 immediate neighbors get equal weight
- For k=2 with gaussian: ring-1 neighbors get more weight than ring-2

### 5.2 Implementation

Create `app/Services/SpatialSmoothingService.php`:

```php
class SpatialSmoothingService
{
    public function smooth(int $year, int $resolution, SmoothingConfig $config): void
    {
        // For each H3 cell with a score:
        // 1. Get its h3_grid_disk neighbors at distance 1..k_rings
        // 2. Look up their raw scores
        // 3. Compute weighted average
        // 4. Store as score_smoothed
    }
}
```

**SQL-based approach (more efficient):**

```sql
-- k=1, uniform neighbor weighting
UPDATE h3_scores target
SET score_smoothed = (
    :self_weight * target.score_raw +
    :neighbor_weight * COALESCE(neighbors.avg_score, target.score_raw)
),
smoothing_factor = :neighbor_weight
FROM (
    SELECT
        center.h3_index,
        AVG(neighbor_scores.score_raw) AS avg_score
    FROM h3_scores center
    CROSS JOIN LATERAL (
        SELECT h3_grid_disk(center.h3_index::h3index, 1) AS neighbor_h3
    ) ring
    LEFT JOIN h3_scores neighbor_scores
        ON neighbor_scores.h3_index = ring.neighbor_h3::text
        AND neighbor_scores.year = center.year
        AND neighbor_scores.h3_index != center.h3_index
    WHERE center.year = :year
    GROUP BY center.h3_index
) neighbors
WHERE target.h3_index = neighbors.h3_index
  AND target.year = :year;
```

**Important edge cases:**
- Hexes at Sweden's border have fewer than 6 neighbors (some are ocean). The average should only consider neighbors that have scores.
- Hexes in sparsely populated areas may have neighbors with no score (if the DeSO has no data for an indicator). Use the hex's own score for missing neighbors.
- The 12 pentagons in H3 have 5 neighbors instead of 6. At resolution 8, there's only one pentagon that could be near Sweden — check the h3 docs but this is unlikely to matter.

### 5.3 Artisan Command

```bash
php artisan smooth:h3-scores [--year=2024] [--config=Light]
```

### 5.4 Performance

~600,000 hexes × 7 lookups each (self + 6 neighbors) = ~4.2M lookups. With proper indexing on `h3_index`, this should complete in under a minute. For k=2 (19 hexes per lookup), ~11.4M lookups — still fast.

### 5.5 Verification

```sql
-- Smoothed scores should have lower variance than raw scores
SELECT
    'raw' AS type,
    AVG(score_raw) AS mean,
    STDDEV(score_raw) AS stddev,
    MIN(score_raw) AS min,
    MAX(score_raw) AS max
FROM h3_scores WHERE year = 2024
UNION ALL
SELECT
    'smoothed',
    AVG(score_smoothed),
    STDDEV(score_smoothed),
    MIN(score_smoothed),
    MAX(score_smoothed)
FROM h3_scores WHERE year = 2024;
-- Smoothed should have lower stddev (scores pulled toward neighbors)

-- Check a known sharp boundary (e.g., Danderyd/Rinkeby area)
-- The raw scores should show a sharp jump; smoothed should show a gradient
SELECT
    h3_index,
    primary_deso_code,
    score_raw,
    score_smoothed,
    score_raw - score_smoothed AS smoothing_delta
FROM h3_scores
WHERE primary_deso_code IN (
    SELECT deso_code FROM deso_areas WHERE kommun_name IN ('Danderyd', 'Stockholm')
)
AND year = 2024
ORDER BY score_raw - score_smoothed DESC
LIMIT 20;
-- Expect: large smoothing_delta for hexes near sharp DeSO boundaries
```

---

## Step 6: H3 GeoJSON API

### 6.1 Endpoint

```php
Route::get('/api/h3/scores', [H3Controller::class, 'scores']);
Route::get('/api/h3/geojson', [H3Controller::class, 'geojson']);
```

### 6.2 Scores Endpoint (lightweight)

Returns H3 index + score pairs, frontend converts to geometry client-side using h3-js:

```php
public function scores(Request $request)
{
    $year = $request->integer('year', now()->year - 1);
    $smoothed = $request->boolean('smoothed', true);
    $scoreCol = $smoothed ? 'score_smoothed' : 'score_raw';

    $scores = DB::table('h3_scores')
        ->where('year', $year)
        ->where('resolution', 8)
        ->whereNotNull($scoreCol)
        ->select('h3_index', DB::raw("$scoreCol as score"), 'trend_1y', 'primary_deso_code')
        ->get();

    return response()->json($scores)
        ->header('Cache-Control', 'public, max-age=3600');
}
```

### 6.3 GeoJSON Endpoint (heavy, for fallback/debugging)

Pre-generates GeoJSON with hex boundaries. Use for debugging or if client-side h3-js conversion is too slow:

```php
public function geojson(Request $request)
{
    $year = $request->integer('year', now()->year - 1);
    $smoothed = $request->boolean('smoothed', true);
    $bounds = $request->input('bounds'); // Optional viewport filter

    // Build GeoJSON using h3_cell_to_boundary_geometry in SQL
    $features = DB::select("
        SELECT
            hs.h3_index,
            hs.{$scoreCol} AS score,
            hs.primary_deso_code,
            ST_AsGeoJSON(h3_cell_to_boundary_geometry(hs.h3_index::h3index)) AS geometry
        FROM h3_scores hs
        WHERE hs.year = ?
          AND hs.resolution = 8
          AND hs.{$scoreCol} IS NOT NULL
    ", [$year]);

    // Format as GeoJSON FeatureCollection
    // ...
}
```

**Warning:** Generating 600,000 hex boundaries as GeoJSON will be a huge response (~100-200MB). The viewport-filtered version is essential for production.

### 6.4 Viewport-Based Loading (Critical for Performance)

**~600,000 hexagons is too many to render at once.** The frontend must request hexes for the current viewport only.

```php
Route::get('/api/h3/viewport', [H3Controller::class, 'viewport']);

public function viewport(Request $request)
{
    $request->validate([
        'bbox' => 'required|string',  // "minLng,minLat,maxLng,maxLat"
        'zoom' => 'required|integer',
    ]);

    [$minLng, $minLat, $maxLng, $maxLat] = explode(',', $request->bbox);
    $year = $request->integer('year', now()->year - 1);
    $smoothed = $request->boolean('smoothed', true);
    $scoreCol = $smoothed ? 'score_smoothed' : 'score_raw';

    // Determine appropriate resolution based on zoom
    $resolution = $this->zoomToResolution($request->integer('zoom'));

    if ($resolution == 8) {
        // Full resolution: return hex index + score, let frontend compute boundaries
        $scores = DB::table('h3_scores')
            ->where('year', $year)
            ->where('resolution', 8)
            ->whereNotNull($scoreCol)
            // Filter by viewport using primary_deso_code → deso_areas geometry
            // OR: use h3_cell_to_lat_lng to filter by bounds
            ->whereRaw("h3_cell_to_lat_lng(h3_index::h3index) && ST_MakeEnvelope(?, ?, ?, ?, 4326)", [
                $minLng, $minLat, $maxLng, $maxLat
            ])
            ->select('h3_index', DB::raw("$scoreCol as score"), 'primary_deso_code')
            ->get();
    } else {
        // Lower resolution: aggregate to parent hexes for zoomed-out view
        // Use h3_cell_to_parent() to roll up
        $scores = DB::select("
            SELECT
                h3_cell_to_parent(h3_index::h3index, ?)::text AS h3_index,
                AVG($scoreCol) AS score
            FROM h3_scores
            WHERE year = ?
              AND resolution = 8
              AND $scoreCol IS NOT NULL
            GROUP BY h3_cell_to_parent(h3_index::h3index, ?)
        ", [$resolution, $year, $resolution]);
    }

    return response()->json([
        'resolution' => $resolution,
        'features' => $scores,
    ])->header('Cache-Control', 'public, max-age=300');
}

private function zoomToResolution(int $zoom): int
{
    // Map OpenLayers zoom levels to H3 resolutions
    return match(true) {
        $zoom <= 6 => 5,    // Country view: ~5.16 km² hexes (~87K total)
        $zoom <= 8 => 6,    // Region view: ~0.74 km² hexes
        $zoom <= 10 => 7,   // City view
        default => 8,        // Neighborhood view (full resolution)
    };
}
```

### 6.5 Multi-Resolution Strategy

**This is critical for performance.** You cannot render 600K hexagons at zoom level 5 (country view).

| Zoom level | H3 resolution | Hex count (Sweden) | Hex size |
|------------|--------------|-------------------|----------|
| 4-6 (country) | 5 | ~87,000 | ~253 km² |
| 7-8 (region) | 6 | ~600,000 ÷ 7 ≈ 87K | ~36 km² |
| 9-10 (city) | 7 | ~600,000 | ~5.16 km² |
| 11+ (neighborhood) | 8 | viewport subset | ~0.74 km² |

At zoom levels 4-6, aggregate H3 res-8 scores up to res-5 or res-6 parents using `h3_cell_to_parent()`. The database handles this efficiently.

**Alternative:** Pre-compute res-5, res-6, and res-7 scores in addition to res-8. Adds ~170K rows but makes viewport queries instant. Recommended.

---

## Step 7: Frontend — H3 Layer in OpenLayers

### 7.1 h3-js Client Library

Install:
```bash
npm install h3-js
```

Use `cellToBoundary()` from h3-js to convert H3 indexes to polygon coordinates client-side. This avoids sending 600K polygon geometries from the backend — send only the index strings + scores.

```typescript
import { cellToBoundary } from 'h3-js';

function h3ToFeature(h3Index: string, score: number): Feature {
    const boundary = cellToBoundary(h3Index, true); // true = GeoJSON format [lng, lat]
    return new Feature({
        geometry: new Polygon([boundary]),
        h3Index,
        score,
    });
}
```

### 7.2 H3 Vector Layer

Create `resources/js/Components/H3Layer.tsx` (or integrate into existing `DesoMap.tsx`):

```typescript
// Create a separate vector layer for H3 hexagons
const h3Source = new VectorSource();
const h3Layer = new VectorLayer({
    source: h3Source,
    style: (feature) => {
        const score = feature.get('score');
        return new Style({
            fill: new Fill({ color: scoreToColor(score) }),
            stroke: new Stroke({
                color: 'rgba(255, 255, 255, 0.15)',
                width: 0.5,
            }),
        });
    },
    zIndex: 1,  // Below school markers, above basemap
});
```

### 7.3 Viewport-Based Loading

When the map viewport changes (pan, zoom), fetch hexes for the visible area:

```typescript
map.on('moveend', debounce(() => {
    const extent = map.getView().calculateExtent(map.getSize());
    const [minLng, minLat, maxLng, maxLat] = transformExtent(extent, 'EPSG:3857', 'EPSG:4326');
    const zoom = Math.round(map.getView().getZoom());

    fetch(`/api/h3/viewport?bbox=${minLng},${minLat},${maxLng},${maxLat}&zoom=${zoom}&smoothed=${isSmoothed}`)
        .then(res => res.json())
        .then(data => {
            h3Source.clear();
            const features = data.features.map(f =>
                h3ToFeature(f.h3_index, f.score)
            );
            h3Source.addFeatures(features);
        });
}, 300));
```

### 7.4 Click Interaction for H3

When a user clicks a hex, we need to:
1. Identify which hex was clicked
2. Find the primary DeSO for that hex
3. Load the DeSO's data into the sidebar (scores, indicators, schools)

```typescript
map.on('click', (event) => {
    const feature = map.forEachFeatureAtPixel(event.pixel, f => f, {
        layerFilter: layer => layer === h3Layer,
    });

    if (feature) {
        const h3Index = feature.get('h3Index');
        const primaryDeso = feature.get('primaryDesoCode');
        // Load sidebar data for this DeSO
        loadDesoData(primaryDeso);
        // Highlight the DeSO polygon (even if we're viewing hexes)
        highlightDeso(primaryDeso);
        // Load school markers for the DeSO
        loadSchoolMarkers(primaryDeso);
    }
});
```

**Key decision:** When clicking a hex, the sidebar shows the **DeSO's** data (since that's where all the detailed indicator data lives). The hex is just a visual unit — the data granularity is still DeSO. This is important to communicate to the user.

### 7.5 Hover Tooltip

```typescript
map.on('pointermove', (event) => {
    const feature = map.forEachFeatureAtPixel(event.pixel, f => f, {
        layerFilter: layer => layer === h3Layer,
    });

    if (feature) {
        const score = feature.get('score');
        const deso = feature.get('primaryDesoCode');
        showTooltip(event.pixel, `Score: ${score.toFixed(1)} | DeSO: ${deso}`);
    } else {
        hideTooltip();
    }
});
```

---

## Step 8: Layer Control

### 8.1 Toggle Component

Create a small control overlay on the map (top-right, below zoom controls):

```
┌─────────────────────┐
│  View                │
│  ◉ Hexagons          │
│  ○ Statistical Areas │
├─────────────────────┤
│  ☐ Raw scores        │ ← Only visible for admin/research
└─────────────────────┘
```

Use shadcn `RadioGroup` and `Checkbox` inside a floating card overlaid on the map.

### 8.2 Layer Switching Logic

When toggling between Hexagons and Statistical Areas:
- **Hexagons:** Show `h3Layer`, hide `desoLayer`. Load data from `/api/h3/viewport`.
- **Statistical Areas (DeSO):** Show `desoLayer`, hide `h3Layer`. Load data from existing `/api/deso/geojson` + `/api/deso/scores`.

Both layers support the same click→sidebar interaction. The sidebar always shows DeSO-level data regardless of which layer is active.

**Default:** Hexagons. DeSO polygons are the "expert/raw" view.

### 8.3 Smoothing Toggle

The "Raw scores" checkbox only appears for admin/research users (check a prop passed from the backend, e.g., `isAdmin` or a feature flag).

When toggled:
- **Checked (raw):** Re-fetch hex scores with `?smoothed=false`
- **Unchecked (smoothed):** Re-fetch with `?smoothed=true` (default)

This affects **only the H3 layer**. The DeSO polygon view always shows the original composite scores (which are inherently "raw" at DeSO level).

### 8.4 Mobile

On mobile (<768px), the layer control becomes a simple icon button that opens a small popover. Default to hexagons, no smoothing toggle on mobile.

---

## Step 9: Update Existing Score Pipeline

### 9.1 After Composite Scores Are Computed

The existing `compute:scores` command should trigger H3 projection and smoothing:

```bash
php artisan compute:scores --year=2024
# ↓ automatically triggers:
php artisan project:scores-to-h3 --year=2024
php artisan smooth:h3-scores --year=2024 --config=Light
```

Or chain them in the command:

```php
// In ComputeScores command, after computing DeSO scores:
$this->call('project:scores-to-h3', ['--year' => $year]);
$this->call('smooth:h3-scores', ['--year' => $year, '--config' => 'Light']);
```

### 9.2 Admin: Smoothing Control

On the admin indicators page, add a section for smoothing configuration:

```
Spatial Smoothing
─────────────────
Active config: Light (70% self, 30% neighbors, 1 ring)

[None] [Light] [Medium] [Strong]

When changed → recompute smoothed scores (no need to recompute DeSO scores)
```

Changing the smoothing config only re-runs `smooth:h3-scores`, which takes ~1 minute. Much faster than recomputing everything.

---

## Step 10: Full Pipeline Test

### 10.1 Build Sequence

```bash
# 1. Build the DeSO→H3 mapping (one-time, ~10-30 min)
php artisan build:deso-h3-mapping --resolution=8

# 2. Project existing composite scores to H3
php artisan project:scores-to-h3 --year=2024

# 3. Apply spatial smoothing
php artisan smooth:h3-scores --year=2024 --config=Light

# 4. Verify
```

### 10.2 Database Verification

```sql
-- Mapping completeness
SELECT COUNT(*) FROM deso_h3_mapping;  -- ~500K-700K
SELECT COUNT(DISTINCT deso_code) FROM deso_h3_mapping;  -- 6,160
SELECT COUNT(DISTINCT h3_index) FROM deso_h3_mapping;  -- ~500K-700K

-- Score projection
SELECT COUNT(*) FROM h3_scores WHERE year = 2024;  -- Same as mapping count
SELECT COUNT(*) FROM h3_scores WHERE score_raw IS NOT NULL AND year = 2024;
SELECT COUNT(*) FROM h3_scores WHERE score_smoothed IS NOT NULL AND year = 2024;

-- Smoothing effect
SELECT
    ROUND(AVG(ABS(score_raw - score_smoothed))::numeric, 2) AS avg_delta,
    ROUND(MAX(ABS(score_raw - score_smoothed))::numeric, 2) AS max_delta,
    ROUND(STDDEV(score_raw)::numeric, 2) AS raw_stddev,
    ROUND(STDDEV(score_smoothed)::numeric, 2) AS smoothed_stddev
FROM h3_scores WHERE year = 2024;
-- Expect: smoothed_stddev < raw_stddev

-- Visual sanity: Danderyd area hexes (should be green)
SELECT h3_index, score_raw, score_smoothed, primary_deso_code
FROM h3_scores
WHERE primary_deso_code LIKE '0162%'  -- Danderyd kommun
AND year = 2024
ORDER BY score_smoothed DESC LIMIT 10;
```

### 10.3 Visual Checklist

- [ ] **Hexagonal grid is the default view** — no DeSO polygon borders visible on first load
- [ ] Hexagons are colored by the same purple→green gradient as before
- [ ] **Smooth transitions visible** between high and low scoring areas (no jarring hard edges)
- [ ] Zooming out aggregates to larger hexagons (not 600K tiny hexes at country view)
- [ ] Zooming in shows full-resolution hexes at neighborhood level
- [ ] Layer toggle works: switching to "Statistical Areas" shows the original DeSO polygons
- [ ] Switching back to hexagons works without page reload
- [ ] **Clicking a hex** opens the sidebar with DeSO data (score, indicators, schools)
- [ ] School markers still appear correctly for the selected area
- [ ] Admin "Raw scores" toggle shows unsmoothed hexes (sharper boundaries visible)
- [ ] Admin smoothing config selector works and re-smoothing updates the map
- [ ] Mobile: hexagon layer works on touch, layer toggle accessible
- [ ] Scrolling/panning is smooth at city zoom level (~1000-5000 hexes in viewport)
- [ ] No visible rendering lag when panning at country zoom level

### 10.4 Sanity: Before/After Comparison

Toggle between H3 and DeSO views in the Stockholm area:
- Danderyd→Rinkeby boundary: DeSO view should show a hard jump; H3 smoothed view should show a gradient of 3-5 transitional hexes
- Inner city Stockholm: H3 view should be mostly uniform green/yellow; DeSO view might show more patch variation
- Rural Norrland: both views should look similar (large areas, gradual changes anyway)

---

## Notes for the Agent

### OpenLayers, Not Deck.gl

The project uses OpenLayers (see `CLAUDE.md`). Deck.gl has a native `H3HexagonLayer` that would be ideal, but we're committed to OpenLayers. The approach is:
1. Backend sends H3 indexes + scores (not geometries)
2. Frontend uses `h3-js` to convert indexes to polygon boundaries
3. OpenLayers renders them as regular polygon features in a VectorLayer

This works fine. The h3-js `cellToBoundary()` call is fast (<1ms per hex). For 5,000 hexes in a viewport, conversion takes <5 seconds. With WebGL rendering in OpenLayers (optional optimization), even 10,000 polygons render smoothly.

### Performance Is the Main Risk

600K hexagons covering all of Sweden is a lot. The multi-resolution strategy (Step 6.5) is not optional — it's essential. At country zoom, show ~87K resolution-5 hexes. At city zoom, show resolution-7 or resolution-8 for the viewport only.

If OpenLayers VectorLayer chokes on >10K features, consider:
1. **WebGL rendering:** OpenLayers has a `WebGLVectorLayer` that handles large feature counts
2. **Vector tiles:** Pre-generate H3 hex boundaries as vector tiles (MVT) and serve via a tile server
3. **Canvas-based custom rendering:** Skip the Feature abstraction entirely and draw directly on a Canvas layer using the hex coordinates

Start with the simple VectorSource approach. Only optimize if performance is actually a problem.

### The Sidebar Still Shows DeSO Data

Clicking a hex resolves to its `primary_deso_code` and loads that DeSO's data. The hex is a visual unit; the DeSO is the data unit. This is fine — users don't care about the distinction. The sidebar shows "Area: [DeSO name]" and the factor breakdown. The hex visualization just makes the map look better and more scientifically credible.

### Smoothing Is Configurable, Not Magic

The admin can tune or disable smoothing. Some use cases want sharp boundaries:
- A bank doing risk assessment might want raw, unsmoothed scores
- A researcher studying neighborhood effects wants to see the actual data, not interpolated values
- A homebuyer wants the visual clarity of gradual transitions

That's why the toggle exists. Default is smoothed for consumer-facing; raw available for power users.

### What NOT to Do

- Don't try to render all 600K hexes at once — viewport-based loading is mandatory
- Don't remove the DeSO layer entirely — it must remain as a toggle option
- Don't change the scoring engine — it still works at DeSO level; H3 is a projection layer
- Don't compute indicator-level data at H3 level — that's a much bigger refactor for later
- Don't use Deck.gl — stay with OpenLayers per project rules
- Don't over-engineer the smoothing — k=1 linear is the right v1

### What to Prioritize

1. Get h3-pg installed and the mapping table built (this unblocks everything)
2. Get hexes rendering on the map for a single viewport
3. Get viewport-based loading working at multiple zoom levels
4. Add the layer toggle
5. Add smoothing (this can come last — even unsmoothed hexes are better than DeSO polygons because they look uniform and professional)
6. Admin smoothing controls are polish

### Update CLAUDE.md

After implementation, add:
- h3-pg installation notes and version
- H3 resolution decisions and hex counts
- Performance benchmarks (mapping build time, viewport query times)
- Any OpenLayers rendering lessons (WebGL? canvas? feature count limits?)
- Smoothing config that works best visually