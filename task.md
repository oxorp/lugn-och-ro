# TASK: Fix Map Performance — Pin-Drop Hot Path

## Context

A performance audit revealed the map tiles themselves are fine (heatmap PNGs, 60fps). The lag users feel is the **pin-drop interaction flow** — clicking the map triggers 10-12 sequential PostGIS queries that take 300-600ms in Stockholm, returns 3,767 unbounded POI markers, and runs duplicate spatial work across two services.

This task fixes the five issues found, in priority order.

---

## P0: Split POIs into "Render on Map" vs "Count in Sidebar"

### The Problem

`LocationController` queries POIs with no `LIMIT`. In central Stockholm this returns 3,767 rows, all serialized to JSON, all rendered as individual OpenLayers features with icons and hit-testing. This is the single biggest cause of perceived lag.

But hard-capping at 200 is wrong — it could cut the one school or transit stop that matters. The real issue is that **most POI types don't need map markers at all.** Nobody needs to see 847 individual café pins. They need to see "23 cafés nearby" in the sidebar and the 3 nearest schools as markers.

### The Fix: Render Tiers

Add a `render_on_map` boolean to `poi_categories` (or use a config). POI types are split into two tiers:

**Tier 1 — Render as map markers** (things users navigate to, need to see location of):
- Schools (grundskola, gymnasie)
- Transit stops (major only — rail, subway, high-frequency bus)
- Grocery stores
- Healthcare (vårdcentral, hospital)
- Negative POIs (gambling, pawn shops) — user needs to see WHERE they are

**Tier 2 — Count only, show in sidebar/report** (ambient neighborhood character):
- Restaurants & cafés
- Gyms & fitness
- Parks & green spaces (the area score already captures this; exact pin less useful)
- Pharmacies
- Retail / specialty shops
- Cultural venues
- Any other "nice to have" category

**Implementation:**

```php
// Migration: add render tier to poi_categories
Schema::table('poi_categories', function (Blueprint $table) {
    $table->boolean('render_on_map')->default(false);
});

// Seed the tiers
DB::table('poi_categories')
    ->whereIn('slug', [
        'school', 'grundskola', 'gymnasieskola',
        'transit_stop', 'rail_station', 'subway_station',
        'grocery', 'supermarket',
        'healthcare', 'hospital', 'vardcentral',
        'gambling', 'pawn_shop', 'fast_food_cluster',
    ])
    ->update(['render_on_map' => true]);
```

**API response changes:**

The endpoint returns two sections:

```php
public function show(Request $request)
{
    // ... existing DeSO/score logic ...

    // Tier 1: Full details + coordinates (for map markers)
    $mapPois = Poi::query()
        ->join('poi_categories', 'poi_categories.id', '=', 'pois.poi_category_id')
        ->where('poi_categories.render_on_map', true)
        ->where(DB::raw("ST_DWithin(pois.geom::geography, ..., {$radius})"))
        ->orderByRaw("pois.geom::geography <-> ...")
        ->select('pois.*', 'poi_categories.name as category_name', 'poi_categories.slug as category_slug')
        ->limit(100) // Safety cap — Tier 1 categories won't hit this in practice
        ->get();

    // Tier 2: Counts only (for sidebar stats)
    $sidebarCounts = Poi::query()
        ->join('poi_categories', 'poi_categories.id', '=', 'pois.poi_category_id')
        ->where('poi_categories.render_on_map', false)
        ->where(DB::raw("ST_DWithin(pois.geom::geography, ..., {$radius})"))
        ->groupBy('poi_categories.slug', 'poi_categories.name')
        ->select(
            'poi_categories.slug',
            'poi_categories.name',
            DB::raw('COUNT(*) as count'),
            DB::raw('MIN(ST_Distance(pois.geom::geography, ...)) as nearest_m')
        )
        ->get();

    return response()->json([
        'map_pois' => $mapPois,       // ~30-60 features with coordinates
        'sidebar_counts' => $sidebarCounts,  // ~10-15 rows, no coordinates
        // ... scores, schools, indicators ...
    ]);
}
```

**Frontend changes:**

```tsx
// Only map_pois become OpenLayers features
mapPois.forEach(poi => addMarkerToLayer(poi));

// sidebar_counts render as text in the sidebar
<div className="space-y-2">
    <h4>Nearby</h4>
    {sidebarCounts.map(cat => (
        <div key={cat.slug} className="flex justify-between text-sm">
            <span>{cat.name}</span>
            <span className="text-muted-foreground">
                {cat.count} ({Math.round(cat.nearest_m)}m)
            </span>
        </div>
    ))}
</div>
```

### Why This Works

- Stockholm central drops from 3,767 markers to ~30-60 (schools + transit + grocery + healthcare + negative POIs)
- Zero data loss — every café and gym is still counted and shown in the sidebar
- The categories that users actually click on (schools, transit) keep full markers with tooltips
- Ambient categories (cafés, restaurants) become neighborhood character stats — which is what they really are
- The `render_on_map` boolean is admin-configurable — if a category becomes important later, flip the flag

### Verify

```sql
-- Count Tier 1 POIs within 1km of Stockholm central
SELECT pc.slug, COUNT(*)
FROM pois p
JOIN poi_categories pc ON pc.id = p.poi_category_id
WHERE pc.render_on_map = true
  AND ST_DWithin(p.geom::geography, ST_SetSRID(ST_MakePoint(18.0586, 59.3308), 4326)::geography, 1000)
GROUP BY pc.slug;
-- Should total 30-80 features — manageable

-- Verify nothing important is missing from Tier 1
SELECT pc.slug, pc.render_on_map, COUNT(*) as within_1km
FROM pois p
JOIN poi_categories pc ON pc.id = p.poi_category_id
WHERE ST_DWithin(p.geom::geography, ST_SetSRID(ST_MakePoint(18.0586, 59.3308), 4326)::geography, 1000)
GROUP BY pc.slug, pc.render_on_map
ORDER BY count DESC;
-- Restaurants/cafés should be Tier 2 (render_on_map=false) — that's where the bulk is
```

---

## P1: Deduplicate and Combine Spatial Queries

### The Problem

Two services query the same data for the same point:

1. **`ProximityScoreService::score()`** runs 7 sequential `ST_DWithin` queries to compute proximity scores (schools, green space, transit, grocery, negative POIs, positive POIs, plus DeSO lookup + safety)
2. **`LocationController::show()`** then runs 3+ more `ST_DWithin` queries for schools, POIs, and categories — fetching the same nearby features again with different parameters

That's 10-12 spatial queries hitting the same PostGIS indexes for the same coordinates. Most of this is duplicate work.

### The Fix

**Option A: Share query results (simpler)**

Refactor `LocationController::show()` to call `ProximityScoreService` once and reuse the intermediate results:

```php
public function show(Request $request)
{
    $lat = $request->float('lat');
    $lng = $request->float('lng');

    // One service call that returns BOTH scores AND the raw nearby features
    $proximity = $this->proximityService->scoreWithDetails($lat, $lng);

    // $proximity now contains:
    // - score (the composite proximity score)
    // - factor_scores (per-category scores)
    // - nearby_schools (the actual school records, already fetched)
    // - nearby_pois (the actual POI records, already fetched)
    // - nearby_transit (the actual transit stops, already fetched)

    // No need to re-query — just format for the frontend
    $schools = $proximity->nearby_schools->map(fn ($s) => [...]);
    $pois = $proximity->nearby_pois->map(fn ($p) => [...]);

    // ... build response using shared data
}
```

**Option B: Single CTE query (more effort, bigger win)**

Combine all spatial lookups into one round-trip using a CTE:

```sql
WITH pin AS (
    SELECT ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography AS geog
),
nearby_deso AS (
    SELECT deso_code, geom
    FROM deso_areas, pin
    WHERE ST_Contains(deso_areas.geom, pin.geog::geometry)
    LIMIT 1
),
nearby_schools AS (
    SELECT s.*, ss.merit_value_17, ss.goal_achievement_pct,
           ST_Distance(s.geom::geography, pin.geog) AS distance_m
    FROM schools s
    CROSS JOIN pin
    LEFT JOIN school_statistics ss ON ss.school_unit_code = s.school_unit_code
        AND ss.academic_year = (SELECT MAX(academic_year) FROM school_statistics WHERE school_unit_code = s.school_unit_code)
    WHERE ST_DWithin(s.geom::geography, pin.geog, 2000)
      AND s.status = 'active'
    ORDER BY distance_m
    LIMIT 15
),
nearby_pois AS (
    SELECT p.*, pc.name as category_name, pc.sentiment,
           ST_Distance(p.geom::geography, pin.geog) AS distance_m
    FROM pois p
    CROSS JOIN pin
    JOIN poi_categories pc ON pc.id = p.poi_category_id
    WHERE ST_DWithin(p.geom::geography, pin.geog, 1000)
    ORDER BY distance_m
    LIMIT 200
)
SELECT 'deso' AS result_type, nearby_deso.deso_code AS id, NULL AS data FROM nearby_deso
UNION ALL
SELECT 'school', school_unit_code, row_to_json(nearby_schools.*)::text FROM nearby_schools
UNION ALL
SELECT 'poi', id::text, row_to_json(nearby_pois.*)::text FROM nearby_pois;
```

This is one query, one round-trip, one index scan sweep. PostGIS is extremely efficient at batching spatial operations in a single query plan.

**Recommendation:** Start with Option A (30 minutes, immediate dedup). Do Option B later if latency is still >200ms.

### Verify

Add timing to the controller:

```php
$start = microtime(true);
// ... all queries ...
$elapsed = (microtime(true) - $start) * 1000;
Log::info("Pin-drop response: {$elapsed}ms", ['lat' => $lat, 'lng' => $lng]);
```

Test with Stockholm coordinates (59.33, 18.07). Should drop from 300-600ms to 100-200ms.

---

## P2: Throttle the Hover Handler

### The Problem

`deso-map.tsx` line ~563 registers a `pointermove` handler that calls `forEachFeatureAtPixel` on every mouse movement. With 200 POI markers loaded (3,767 before the P0 fix), this is expensive hit-testing running at 60Hz.

### The Fix

Debounce or throttle to 50ms:

```tsx
// BEFORE
map.on('pointermove', (evt) => {
    const feature = map.forEachFeatureAtPixel(evt.pixel, (f) => f);
    // ... tooltip logic
});

// AFTER
import { throttle } from 'lodash'; // or a simple manual throttle

const handlePointerMove = throttle((evt: MapBrowserEvent<UIEvent>) => {
    const feature = map.forEachFeatureAtPixel(evt.pixel, (f) => f);
    // ... tooltip logic
}, 50);

map.on('pointermove', handlePointerMove);

// IMPORTANT: Clean up on unmount
return () => {
    map.un('pointermove', handlePointerMove);
    handlePointerMove.cancel();
};
```

**Alternative:** Use `pointerInteractionOptions` on the layer to restrict which layers participate in hit-testing. If tooltips only apply to POI/school markers, exclude the heatmap tile layer from hit-testing.

```tsx
const poiLayer = new VectorLayer({
    source: poiSource,
    // Only this layer responds to hover
});

map.on('pointermove', throttle((evt) => {
    // Only check the POI layer, not all layers
    const hit = map.forEachFeatureAtPixel(evt.pixel, (f) => f, {
        layerFilter: (layer) => layer === poiLayer || layer === schoolLayer,
    });
}, 50));
```

### Verify

Open DevTools Performance tab, mouse around the map quickly in Stockholm. Frame times should stay under 16ms (60fps). No long `pointermove` handler tasks in the flame chart.

---

## P3: Cache Proximity Scores by Grid Cell

### The Problem

Every pin-drop runs the full `ProximityScoreService::score()` computation — 7 spatial queries. Two clicks 50 meters apart produce nearly identical results but cost the same computation.

### The Fix

Round coordinates to a ~100m grid and cache results:

```php
// In ProximityScoreService or LocationController

public function getCachedScore(float $lat, float $lng): ProximityResult
{
    // Round to ~100m precision (3 decimal places ≈ 111m lat, ~55-80m lng in Sweden)
    $gridLat = round($lat, 3);
    $gridLng = round($lng, 3);
    $cacheKey = "proximity:{$gridLat},{$gridLng}";

    return Cache::remember($cacheKey, now()->addHour(), function () use ($lat, $lng) {
        return $this->score($lat, $lng);
    });
}
```

**Cache invalidation:** Proximity data changes when:
- New POIs are ingested (monthly) → flush all `proximity:*` keys after POI ingestion
- School data updates (monthly) → same
- Score recomputation → same

For simplicity, just flush the entire proximity cache after any data pipeline run:

```php
// In any ingestion command, after completion
Cache::flush(); // or more targeted: clear keys with 'proximity:' prefix
```

**Trade-off:** First click on a new grid cell is still slow. Subsequent clicks within ~100m are instant. In practice, users click nearby points when exploring an area, so the cache hit rate will be high.

### Verify

```php
// Click the same area twice, check logs
Log::info("Proximity cache", ['hit' => Cache::has($cacheKey)]);
// Second click should show cache hit
```

---

## P4: Verify Spatial Indexes

### The Problem

If any table is missing its GIST index, every `ST_DWithin` and `ST_Contains` query is a full table scan. With 3,767 POIs and 6,160 DeSO polygons, this would be catastrophic but might not be obvious during development with small datasets.

### The Fix

Run this check and create any missing indexes:

```sql
-- Check existing spatial indexes
SELECT
    tablename,
    indexname,
    indexdef
FROM pg_indexes
WHERE indexdef LIKE '%gist%'
ORDER BY tablename;

-- These MUST exist:
-- deso_areas: GIST index on geom
-- schools: GIST index on geom
-- pois: GIST index on geom
-- (any transit/green_space tables): GIST index on geom

-- If ANY are missing, create them:
CREATE INDEX IF NOT EXISTS deso_areas_geom_idx ON deso_areas USING GIST (geom);
CREATE INDEX IF NOT EXISTS schools_geom_idx ON schools USING GIST (geom);
CREATE INDEX IF NOT EXISTS pois_geom_idx ON pois USING GIST (geom);
```

Also check that the `geography` cast in `ST_DWithin` queries can use the index. If queries cast `geom::geography` and the index is on `geom` (geometry type), PostGIS may not use the index. Options:
- Store a separate `geog` column of type `geography` with its own GIST index
- Or ensure queries use `geom` with `ST_DWithin` in geometry mode (with appropriate SRID transforms)

### Verify

```sql
EXPLAIN ANALYZE
SELECT * FROM pois
WHERE ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(18.0586, 59.3308), 4326)::geography, 1000);
-- Look for "Index Scan" or "Bitmap Index Scan" — NOT "Seq Scan"
```

---

## P5: Clean Up the 115MB Ghost File

### The Problem

`public/data/deso.geojson` is 115MB, never used by the frontend (heatmap tiles replaced it), but sits in the public directory. It inflates Docker images, git repos, and backups.

### The Fix

1. **Verify nothing references it:**
```bash
grep -rn "deso.geojson" resources/js/ app/ routes/ --include='*.tsx' --include='*.ts' --include='*.php' --include='*.vue'
```

2. **If nothing references it:** Delete it.
```bash
rm public/data/deso.geojson
```

3. **If the fallback GeoJSON endpoint (`DesoController.php:46`) still exists** and someone might call it via API:
   - Either delete the endpoint too (if the tile approach fully replaces it)
   - Or fix it to use `ST_SimplifyPreserveTopology` instead of `ST_Buffer` and add aggressive caching:
```php
public function geojson()
{
    return Cache::rememberForever('deso_geojson_simplified', function () {
        $features = DB::table('deso_areas')
            ->select(
                'deso_code',
                DB::raw("ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, 0.001)) as geometry")
            )
            ->get();
        // ... build FeatureCollection
    });
}
```

4. **Add to `.gitignore`** if large generated files shouldn't be tracked:
```
public/data/deso.geojson
```

---

## Execution Order

| Priority | Fix | Effort | Expected Impact |
|---|---|---|---|
| P0 | Render tiers — map markers vs sidebar counts | 45 min | 3,767 → ~50 markers in Stockholm |
| P1 | Deduplicate spatial queries (Option A) | 30-60 min | Cuts query count from 12 to 5-6 |
| P2 | Throttle hover handler | 10 min | Smooth hovering in dense areas |
| P3 | Cache proximity by grid cell | 30 min | Instant repeat lookups |
| P4 | Verify spatial indexes | 10 min | Prevents catastrophic slow queries |
| P5 | Delete 115MB ghost file | 5 min | Housekeeping |

Total: ~2.5-3 hours. P0 + P2 alone (55 minutes) will fix the most visible lag.

---

## Verification

After all fixes, test with Stockholm central (59.3308, 18.0586):

- [ ] Pin-drop API responds in **< 200ms** (check Network tab)
- [ ] Map markers are **only Tier 1 categories** — schools, transit, grocery, healthcare, negative POIs
- [ ] Sidebar shows **counts for Tier 2 categories** — restaurants, cafés, gyms, parks with nearest distance
- [ ] Stockholm central pin-drop renders **< 80 markers** (not 3,767)
- [ ] Zero data loss — every POI type appears somewhere (either as marker or sidebar count)
- [ ] Hovering over markers is smooth, **no frame drops** (check Performance tab)
- [ ] Second click nearby (within ~100m) is **< 50ms** (cache hit)
- [ ] `EXPLAIN ANALYZE` on spatial queries shows **index scans**, not seq scans
- [ ] `public/data/deso.geojson` is **gone** (or simplified + cached if endpoint kept)
- [ ] `poi_categories.render_on_map` column exists and is configurable in admin

---

## What NOT to Do

- **DO NOT touch the heatmap tile rendering.** It's already performant at 60fps. The problem was never the map tiles.
- **DO NOT implement vector tiles or WebGL rendering.** Overkill for this feature count. The bottleneck is server-side queries and response size, not client-side rendering.
- **DO NOT remove the `pointermove` handler entirely.** Hover tooltips are good UX. Just throttle them.
- **DO NOT cache with infinite TTL.** Proximity data changes when POIs/schools update. 1-hour cache with flush-on-ingest is the right balance.