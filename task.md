# TASK: Heatmap + Pin Architecture — Replace DeSO Polygons

## READ THIS FIRST — What We Are Building

The map currently shows ~6,160 DeSO polygon outlines filled with colors. We are replacing this with a **smooth colored overlay that looks like a weather temperature map**. No polygon edges. No hexagon shapes. No circles. Just smooth continuous color blending across the landscape.

The user currently clicks polygons. We are replacing this with **dropping a pin** (click anywhere on the map) or **searching an address**. The pin is the interaction, not the map regions.

**The end result looks like:** Google Maps traffic overlay, or a weather temperature map, or an air quality heatmap. A transparent colored layer draped over the basemap. Green in good areas, purple in bad areas, smooth transitions between them.

**The end result does NOT look like:** Colored polygons with visible edges. Hexagonal grids. Circles. Dots. Any visible geometric shapes.

---

## CRITICAL: How the Heatmap is Rendered

There is exactly ONE approach. Do not improvise alternatives.

### The Approach: Server-Side Pre-Rendered PNG Tiles

We generate PNG tile images on the server using Python. The tiles are served as a standard XYZ tile layer in OpenLayers, exactly like how basemap tiles from OpenStreetMap work.

**Why this and nothing else:**
- OpenLayers `ol.layer.Heatmap` is a DENSITY heatmap (more points = hotter). That is NOT what we want. We want VALUE-based coloring (score = color). **DO NOT USE `ol.layer.Heatmap`.**
- Rendering 47,000 vector features client-side is slow on mobile and looks jagged. **DO NOT render individual points, circles, or hexagons on the client.**
- Client-side WebGL shaders are complex, fragile, and hard to debug. **DO NOT write custom WebGL shaders.**

**What we DO:**
1. A Python script reads H3 scores from the database
2. For each map tile (z/x/y), it paints the H3 hexagon areas with score colors and applies Gaussian blur to remove hard edges
3. Output: PNG files at `storage/app/tiles/{year}/{z}/{x}/{y}.png`
4. OpenLayers loads these as `ol.source.XYZ` — identical to loading OpenStreetMap tiles
5. The tile layer sits on top of the basemap with ~40% opacity

That's it. The frontend code for the heatmap layer is about 10 lines. All the work is in the tile generation script.

---

## Architecture Overview

```
BACKEND (unchanged):
  SCB API → indicator_values (per DeSO) → normalized → composite_scores (per DeSO)

NEW BRIDGE LAYER:
  composite_scores (per DeSO) → deso_h3_mapping → h3_scores (per H3 cell)
  h3_scores → Python tile renderer → PNG tiles on disk

FRONTEND (new):
  OpenLayers basemap + PNG tile overlay (the heatmap)
  User clicks map → pin drops → lat/lng sent to API → sidebar shows data
  User searches address → geocode → pin drops → same flow
  
  NO polygon layer. NO vector features for the heatmap. NO hover tooltips on map.
```

---

## Step 1: DeSO → H3 Mapping Table

### 1.1 Why

Our data lives at DeSO level (6,160 irregular polygons). We need it at H3 level (~47,000 regular hexagons) so we can render smooth tiles. The mapping table tells us which DeSOs contribute to each hex and by how much.

### 1.2 Migrations

```php
Schema::create('h3_cells', function (Blueprint $table) {
    $table->id();
    $table->string('h3_index', 16)->unique()->index();
    $table->integer('resolution')->default(8);
    $table->decimal('center_lat', 10, 7);
    $table->decimal('center_lng', 10, 7);
    $table->timestamps();
});

Schema::create('deso_h3_mapping', function (Blueprint $table) {
    $table->id();
    $table->string('deso_code', 10)->index();
    $table->string('h3_index', 16)->index();
    $table->decimal('area_weight', 8, 6);  // 0.0 to 1.0: fraction of hex inside this DeSO
    $table->timestamps();

    $table->unique(['deso_code', 'h3_index']);
    $table->foreign('deso_code')->references('deso_code')->on('deso_areas');
});

Schema::create('h3_scores', function (Blueprint $table) {
    $table->id();
    $table->string('h3_index', 16)->index();
    $table->integer('year');
    $table->decimal('score', 6, 2);                  // 0-100 composite
    $table->decimal('trend_1y', 6, 2)->nullable();
    $table->json('factor_scores')->nullable();
    $table->json('top_positive')->nullable();
    $table->json('top_negative')->nullable();
    $table->timestamp('computed_at');
    $table->timestamps();

    $table->unique(['h3_index', 'year']);
});
```

### 1.3 Build Script: `scripts/build_deso_h3_mapping.py`

**Dependencies:** `pip install geopandas h3 shapely sqlalchemy psycopg2-binary`

**Algorithm:**
1. Connect to PostgreSQL, load all DeSO polygons from `deso_areas` table
2. For each DeSO polygon:
   a. Convert PostGIS geometry to GeoJSON
   b. `h3.polygon_to_cells(geojson, res=8)` → set of H3 cell indexes
   c. For each cell: compute `area_weight = intersection_area(hex, deso) / hex_area`
   d. Most cells are 100% inside one DeSO. Boundary cells split between 2-3 DeSOs.
3. Insert into `deso_h3_mapping` and `h3_cells` tables
4. Print summary: total cells, boundary cells, avg cells per DeSO

**Expected output:** ~40,000-50,000 H3 cells. ~85% pure (one DeSO), ~15% boundary (multiple DeSOs).

### 1.4 Artisan Wrapper

```bash
php artisan build:deso-h3-mapping [--resolution=8]
```

Calls the Python script via `Process::run()`.

### 1.5 Score Interpolation: `php artisan compute:h3-scores`

For each H3 cell:
- If 100% inside one DeSO: `score = that DeSO's composite_score`
- If straddles multiple DeSOs: `score = Σ(deso_score × area_weight)`

Example: hex is 65% in DeSO A (score 72) and 35% in DeSO B (score 48):
→ `score = 0.65 × 72 + 0.35 × 48 = 63.6`

Writes to `h3_scores` table. Run after `compute:scores` in the pipeline.

---

## Step 2: Tile Generation

### 2.1 The Tile Script: `scripts/generate_heatmap_tiles.py`

This is the core rendering step. It produces PNG images that OpenLayers will display as a tile layer.

**Dependencies:** `pip install geopandas h3 shapely matplotlib pillow numpy sqlalchemy psycopg2-binary`

**How map tiles work (context for the agent):**
- The world is divided into a grid of 256×256px images at each zoom level
- Zoom 0: 1 tile covers the whole world
- Zoom 5: 1,024 tiles. Zoom 10: ~1M tiles. Zoom 14: ~268M tiles.
- Each tile is addressed as z/x/y. OpenLayers requests tiles for the current viewport.
- We only need tiles that cover Sweden, not the whole world.
- We only need zoom levels 5-13. Below 5 is too zoomed out. Above 13, the basemap should dominate.

**Algorithm per tile:**

```python
def render_tile(z, x, y, h3_data):
    """
    z, x, y: tile coordinates
    h3_data: dict of {h3_index: score} loaded from h3_scores table
    Returns: 256x256 RGBA PNG image
    """
    # 1. Compute the geographic bounds of this tile
    bounds = tile_to_bbox(z, x, y)  # returns (west, south, east, north) in EPSG:4326
    
    # 2. Find all H3 cells whose centers fall within these bounds (with some padding)
    cells_in_tile = [
        (h3_index, score) for h3_index, score in h3_data.items()
        if point_in_bounds(h3.cell_to_latlng(h3_index), bounds, padding=0.01)
    ]
    
    # 3. Create a 256x256 image (RGBA, transparent background)
    img = Image.new('RGBA', (256, 256), (0, 0, 0, 0))
    
    # 4. For each H3 cell: draw its hexagonal shape filled with score color
    for h3_index, score in cells_in_tile:
        color = score_to_rgba(score, alpha=180)  # ~70% opacity per hex
        hex_boundary = h3.cell_to_boundary(h3_index)  # list of (lat, lng) vertices
        pixel_coords = [latlng_to_pixel(lat, lng, z, x, y) for lat, lng in hex_boundary]
        draw_filled_polygon(img, pixel_coords, color)
    
    # 5. Apply Gaussian blur to smooth the hex edges into a continuous gradient
    #    σ = 3-5 pixels works well. This is what removes visible hex boundaries.
    img = img.filter(ImageFilter.GaussianBlur(radius=4))
    
    # 6. Save as PNG
    img.save(f"tiles/{year}/{z}/{x}/{y}.png")
```

**Key detail: the Gaussian blur in step 5 is what makes this look like a heatmap instead of a hex grid.** Without blur, you see individual hexagons. With blur (radius 4-6), the edges dissolve and adjacent colors blend into a smooth gradient. This is the entire trick.

### 2.2 Tile Bounds for Sweden

Sweden's bounding box: approximately lat 55.3-69.1, lng 10.9-24.2.

At each zoom level, only generate tiles that intersect this bounding box. This dramatically reduces the number of tiles:

| Zoom | Approx tiles covering Sweden | Notes |
|---|---|---|
| 5 | ~4 | Whole country in a few tiles |
| 6 | ~12 | |
| 7 | ~40 | |
| 8 | ~140 | |
| 9 | ~500 | |
| 10 | ~1,800 | Zoom where city-level detail matters |
| 11 | ~6,500 | |
| 12 | ~25,000 | Neighborhood-level |
| 13 | ~90,000 | Max zoom for heatmap |

**Total: ~125,000 tiles.** At ~5KB per tile average = ~600MB on disk. Generation time: 30-90 minutes with Python.

For v1, generate zoom 5-12 only (~34,000 tiles, ~170MB, ~15 minutes). Add zoom 13 later if needed.

### 2.3 Artisan Command

```bash
php artisan generate:heatmap-tiles [--year=2024] [--zoom-min=5] [--zoom-max=12]
```

Calls `scripts/generate_heatmap_tiles.py`. Stores output in `storage/app/public/tiles/`.

### 2.4 Tile Serving

**Option A (simple):** Serve via Laravel route:
```php
Route::get('/tiles/{year}/{z}/{x}/{y}.png', [TileController::class, 'serve']);
```

**Option B (faster):** Serve directly via nginx, bypassing PHP:
```nginx
location /tiles/ {
    alias /var/www/html/storage/app/public/tiles/;
    add_header Cache-Control "public, max-age=86400";
}
```

Either works. Option A first, optimize to B later.

---

## Step 3: Frontend — The Heatmap Layer

### 3.1 OpenLayers Tile Layer

This is the simple part. Replace the DeSO polygon layer with a tile layer:

```typescript
import TileLayer from 'ol/layer/Tile';
import XYZ from 'ol/source/XYZ';

// REMOVE the old DeSO vector layer entirely.
// REMOVE any GeoJSON loading of DeSO polygons.
// REMOVE any polygon styling code.

// ADD this tile layer:
const heatmapLayer = new TileLayer({
    source: new XYZ({
        url: '/tiles/2024/{z}/{x}/{y}.png',
        minZoom: 5,
        maxZoom: 12,
    }),
    opacity: 0.45,  // semi-transparent so basemap shows through
    zIndex: 1,      // above basemap, below pin marker
});

map.addLayer(heatmapLayer);
```

**That's it for the heatmap rendering.** ~10 lines of frontend code. The hard work is in the tile generation Python script.

### 3.2 What to Remove From the Frontend

Delete or disable ALL of the following:
- DeSO GeoJSON loading (`/api/deso/geojson` fetch)
- DeSO vector layer (the `ol.layer.Vector` that renders polygons)
- DeSO polygon styling (the `scoreToColor` style function applied to polygon features)
- Hover interaction on the map (any `pointermove` listener that shows tooltips)
- Click handler that identifies which DeSO polygon was clicked
- Any code that displays DeSO codes to the user

### 3.3 Opacity by Zoom

Reduce heatmap opacity at higher zoom levels so the basemap (streets, labels) becomes more prominent:

```typescript
map.getView().on('change:resolution', () => {
    const zoom = map.getView().getZoom();
    let opacity = 0.45;
    if (zoom >= 12) opacity = 0.30;
    if (zoom >= 13) opacity = 0.20;
    heatmapLayer.setOpacity(opacity);
});
```

---

## Step 4: Pin Interaction

### 4.1 Click Map → Drop Pin

When the user clicks anywhere on the map:

```typescript
map.on('singleclick', async (event) => {
    const [lng, lat] = ol.proj.toLonLat(event.coordinate);
    
    // 1. Place pin marker at click location
    setPinLocation(lat, lng);
    
    // 2. Fetch data for this location
    const data = await fetch(`/api/location/${lat},${lng}`);
    
    // 3. Update sidebar with data
    setSidebarData(data);
    
    // 4. Update URL
    history.pushState(null, '', `/explore/${lat.toFixed(4)},${lng.toFixed(4)}`);
});
```

### 4.2 Pin Marker

Use a simple, clean OpenLayers overlay or vector feature for the pin:

```typescript
const pinLayer = new ol.layer.Vector({
    source: new ol.source.Vector(),
    zIndex: 10,  // above everything
});

function setPinLocation(lat: number, lng: number) {
    pinLayer.getSource().clear();
    const feature = new ol.Feature({
        geometry: new ol.geom.Point(ol.proj.fromLonLat([lng, lat])),
    });
    feature.setStyle(new ol.style.Style({
        image: new ol.style.Circle({
            radius: 8,
            fill: new ol.style.Fill({ color: '#ffffff' }),
            stroke: new ol.style.Stroke({ color: '#1a1a2e', width: 3 }),
        }),
    }));
    pinLayer.getSource().addFeature(feature);
}
```

The pin is a white circle with a dark border. Simple, visible on any background color. Don't overthink the pin design.

### 4.3 Clearing the Pin

Clicking the sidebar's "X" button or pressing Escape clears the pin and resets the sidebar.

---

## Step 5: Address Search

### 5.1 Geocoding with Photon (Free, No API Key)

```typescript
async function searchAddress(query: string) {
    const response = await fetch(
        `https://photon.komoot.io/api/?q=${encodeURIComponent(query)}&countrycodes=se&lang=sv&limit=5`
    );
    const data = await response.json();
    return data.features.map(f => ({
        name: f.properties.name,
        street: f.properties.street,
        city: f.properties.city,
        lat: f.geometry.coordinates[1],
        lng: f.geometry.coordinates[0],
    }));
}
```

### 5.2 Search Bar in Sidebar

The search bar is at the top of the sidebar, always visible:

```
┌─────────────────────────────────────┐
│ 🔍 Sök adress eller område...       │
└─────────────────────────────────────┘
```

When the user types (debounced 300ms), show a dropdown of suggestions. When they select one, drop a pin at those coordinates and populate the sidebar.

### 5.3 Reverse Geocoding for Sidebar Header

When a pin is dropped (by clicking map or selecting search result), reverse geocode the coordinates to get a human-readable location name:

```
GET https://photon.komoot.io/reverse?lat=59.334&lon=18.065&lang=sv
```

Display in sidebar header: "Vasastan, Stockholms kommun, Stockholms län"

**Fallback:** If reverse geocoding fails or returns vague results, use the kommun/län from the DeSO mapping (the pin's DeSO has kommun_name and lan_name in the database).

---

## Step 6: Backend API

### 6.1 Location Data Endpoint

```php
Route::get('/api/location/{lat},{lng}', [LocationController::class, 'show']);
```

This replaces the old per-DeSO endpoint. Given coordinates, return everything the sidebar needs:

```php
public function show(float $lat, float $lng)
{
    // 1. Find which DeSO this point falls in (PostGIS point-in-polygon)
    $deso = DB::selectOne("
        SELECT deso_code, deso_name, kommun_name, lan_name, area_km2
        FROM deso_areas
        WHERE ST_Contains(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))
        LIMIT 1
    ", [$lng, $lat]);

    if (!$deso) {
        return response()->json(['error' => 'Location outside Sweden'], 404);
    }

    // 2. Get composite score for this DeSO
    $score = CompositeScore::where('deso_code', $deso->deso_code)
        ->orderBy('year', 'desc')
        ->first();

    // 3. Get indicator values for this DeSO
    $indicators = IndicatorValue::where('deso_code', $deso->deso_code)
        ->whereHas('indicator', fn($q) => $q->where('is_active', true))
        ->with('indicator')
        ->orderBy('year', 'desc')
        ->get()
        ->unique('indicator_id');

    // 4. Get nearby schools (radius-based, not DeSO-bounded)
    $schools = DB::select("
        SELECT s.*, ss.merit_value_17, ss.goal_achievement_pct,
               ss.teacher_certification_pct, ss.student_count,
               ST_Distance(s.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m
        FROM schools s
        LEFT JOIN school_statistics ss ON ss.school_unit_code = s.school_unit_code
            AND ss.academic_year = (SELECT MAX(academic_year) FROM school_statistics WHERE school_unit_code = s.school_unit_code)
        WHERE s.status = 'active'
          AND s.geom IS NOT NULL
          AND ST_DWithin(s.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 1500)
        ORDER BY distance_m
        LIMIT 10
    ", [$lng, $lat, $lng, $lat]);

    return response()->json([
        'location' => [
            'lat' => $lat,
            'lng' => $lng,
            'kommun' => $deso->kommun_name,
            'lan' => $deso->lan_name,
            'area_km2' => $deso->area_km2,
        ],
        'score' => $score ? [
            'value' => round($score->score, 1),
            'trend_1y' => $score->trend_1y,
            'label' => $this->scoreLabel($score->score),
            'top_positive' => $score->top_positive,
            'top_negative' => $score->top_negative,
            'factor_scores' => $score->factor_scores,
        ] : null,
        'indicators' => $indicators->map(fn($iv) => [
            'slug' => $iv->indicator->slug,
            'name' => $iv->indicator->name,
            'raw_value' => $iv->raw_value,
            'normalized_value' => $iv->normalized_value,
            'unit' => $iv->indicator->unit,
            'direction' => $iv->indicator->direction,
            'category' => $iv->indicator->category,
        ]),
        'schools' => collect($schools)->map(fn($s) => [
            'name' => $s->name,
            'type' => $s->type_of_schooling,
            'operator_type' => $s->operator_type,
            'distance_m' => round($s->distance_m),
            'merit_value' => $s->merit_value_17,
            'goal_achievement' => $s->goal_achievement_pct,
            'student_count' => $s->student_count,
            'lat' => $s->lat,
            'lng' => $s->lng,
        ]),
    ]);
}

private function scoreLabel(float $score): string
{
    return match(true) {
        $score >= 80 => 'Starkt tillväxtområde',
        $score >= 60 => 'Stabilt / Positivt',
        $score >= 40 => 'Blandat',
        $score >= 20 => 'Förhöjd risk',
        default => 'Hög risk',
    };
}
```

**IMPORTANT:** This endpoint uses DeSO directly via PostGIS `ST_Contains`. It does NOT need H3. The H3 layer is ONLY for rendering the heatmap tiles. Sidebar data comes from the DeSO that contains the pin.

---

## Step 7: Sidebar Redesign

### 7.1 Default State (No Pin)

```
┌─────────────────────────────────────┐
│ 🔍 Sök adress eller område...       │
│                                     │
│       📍                            │
│  Utforska valfri plats              │
│                                     │
│  Sök efter en adress eller klicka   │
│  var som helst på kartan.           │
│                                     │
│  ┌─────────────────────────────┐    │
│  │  Prova: Sveavägen, Stockholm│    │
│  └─────────────────────────────┘    │
│  ┌─────────────────────────────┐    │
│  │  Prova: Kungsbacka          │    │
│  └─────────────────────────────┘    │
│  ┌─────────────────────────────┐    │
│  │  Prova: Lomma               │    │
│  └─────────────────────────────┘    │
│                                     │
│  Grönt = positivt. Lila = risk.     │
│                                     │
└─────────────────────────────────────┘
```

### 7.2 Active State (Pin Dropped)

```
┌─────────────────────────────────────┐
│ 🔍 Vasastan, Stockholm          ✕  │
├─────────────────────────────────────┤
│  Stockholms kommun                  │
│  Stockholms län                     │
│                                     │
│  ┌─────────────┐                    │
│  │     72      │  Stabilt / Positivt│
│  │   ↑ +3.2   │                    │
│  └─────────────┘                    │
│                                     │
│  ── Indikatorer ────────────────    │
│                                     │
│  Medianinkomst      ████████░░  78  │
│  (287 000 kr)                       │
│                                     │
│  Sysselsättning     ██████░░░░  61  │
│  (72.3%)                            │
│                                     │
│  Skolkvalitet       █████████░  91  │
│  (242 meritvärde)                   │
│                                     │
│  ── Skolor (4 inom 1.5 km) ─────   │
│                                     │
│  Årstaskolan              350m      │
│  Grundskola · Kommunal              │
│  Meritvärde: 241                    │
│                                     │
│  Eriksdalsskolan          800m      │
│  Grundskola · Fristående            │
│  Meritvärde: 218                    │
│                                     │
└─────────────────────────────────────┘
```

### 7.3 No DeSO References

Search and destroy ALL user-facing DeSO references:
- ❌ "DeSO: 0180C1090" → gone
- ❌ DeSO code in sidebar header → replaced with kommun/län from reverse geocoding
- ❌ DeSO code in URLs → coordinates instead
- ✅ DeSO stays in database, admin dashboard, artisan command output

---

## Step 8: URL Routing

| URL | State |
|---|---|
| `/` | Map with heatmap, no pin, sidebar shows default state |
| `/explore/59.3340,18.0650` | Pin at those coordinates, sidebar shows data |

When pin is dropped, update URL. When URL contains coordinates, drop pin on page load.

---

## Step 9: School Markers

When a pin is dropped, school markers appear near the pin (within 1.5km radius):

```typescript
// After fetching /api/location/{lat},{lng}, the response includes schools with coordinates.
// Add a small marker for each school on the map.
schools.forEach(school => {
    const feature = new ol.Feature({
        geometry: new ol.geom.Point(ol.proj.fromLonLat([school.lng, school.lat])),
    });
    // Small colored circle: green if merit > 230, yellow if 200-230, orange if < 200
    feature.setStyle(schoolMarkerStyle(school.merit_value));
    schoolLayer.getSource().addFeature(feature);
});
```

When pin moves → clear old school markers, add new ones.
When pin is removed → clear all school markers.

---

## Step 10: Remove Old DeSO Map Code

After the new heatmap + pin system works, delete:

1. The API endpoint `/api/deso/geojson` (or mark deprecated)
2. The API endpoint `/api/deso/scores` (replaced by tile layer)
3. The frontend GeoJSON fetch and vector layer
4. The frontend polygon click handler
5. The frontend hover tooltip handler
6. Any `DesoMap` or similar component that renders polygon features

Keep the DeSO data in the database — it's still the backbone of the pipeline.

---

## Pipeline Order

### One-time setup (run once):
```bash
php artisan build:deso-h3-mapping           # Build DeSO → H3 lookup table
```

### After any score recomputation:
```bash
php artisan compute:h3-scores --year=2024   # Interpolate DeSO scores to H3 cells
php artisan generate:heatmap-tiles --year=2024 --zoom-min=5 --zoom-max=12  # Render PNG tiles
```

### Full pipeline:
```bash
php artisan ingest:scb --all
php artisan normalize:indicators --year=2024
php artisan compute:scores --year=2024
php artisan compute:h3-scores --year=2024
php artisan generate:heatmap-tiles --year=2024
```

---

## Implementation Order

**Do these in order. Do not skip ahead.**

### Phase A: Backend (No UI Changes)
1. Create migrations (h3_cells, deso_h3_mapping, h3_scores)
2. Run migrations
3. Write `scripts/build_deso_h3_mapping.py`
4. Run it: `php artisan build:deso-h3-mapping`
5. Verify: `SELECT COUNT(*) FROM h3_cells;` → expect ~40,000-50,000
6. Write `compute:h3-scores` command
7. Run it: `php artisan compute:h3-scores --year=2024`
8. Verify: `SELECT COUNT(*) FROM h3_scores;` → should match h3_cells count

### Phase B: Tile Generation
9. Write `scripts/generate_heatmap_tiles.py`
10. Run it for a small area first (e.g., just zoom 8-10 for Stockholm bbox)
11. Open a generated PNG tile in an image viewer — should see smooth colored blobs, not visible hexagons
12. If you can see hex edges, increase the Gaussian blur radius
13. Generate full tile set: zoom 5-12

### Phase C: Frontend — Heatmap
14. Add the XYZ tile layer to OpenLayers (10 lines of code)
15. Remove the DeSO polygon vector layer
16. Remove hover tooltip handler
17. Verify: map shows smooth colored overlay, no polygon edges

### Phase D: Frontend — Pin + Sidebar
18. Add click handler that drops a pin marker
19. Create `/api/location/{lat},{lng}` endpoint
20. Wire pin click → API call → sidebar update
21. Add search bar with Photon autocomplete
22. Add reverse geocoding for sidebar header
23. Add URL routing (`/explore/{lat},{lng}`)
24. Add school markers around pin
25. Remove all DeSO references from user-facing UI

---

## Verification

### Tile Quality Check
- [ ] Open a tile PNG file in an image viewer. It should show smooth blended colors, NOT visible hexagonal shapes.
- [ ] Areas with no data should be fully transparent.
- [ ] Color range visible: deep purple (bad) → yellow (mixed) → green (good).

### Visual Check
- [ ] Map shows smooth colored overlay across Sweden (like a temperature map)
- [ ] No polygon edges, no hexagon shapes, no circles visible
- [ ] Basemap (streets, labels) visible through the color overlay
- [ ] Color fades at higher zoom levels so street detail is legible

### Interaction Check
- [ ] Clicking map drops a pin
- [ ] Sidebar shows location name (from reverse geocoding), NOT DeSO code
- [ ] Sidebar shows score, indicators, nearby schools
- [ ] Schools listed with distance ("350m") not DeSO membership
- [ ] Search bar autocompletes Swedish addresses
- [ ] Selecting a search result drops pin and populates sidebar
- [ ] URL updates to `/explore/{lat},{lng}`
- [ ] Loading a URL with coordinates drops pin automatically
- [ ] Pressing X or Escape clears pin and resets sidebar
- [ ] NO hover tooltips on the map
- [ ] Mobile: tapping map drops pin, no hover behavior

### Data Check
- [ ] Score in sidebar matches the colored area on the map (green area → high score)
- [ ] Danderyd area is green, Rinkeby area is purple (sanity check)
- [ ] Schools near the pin are correct (spot-check with Google Maps)

---

## What NOT To Do

Read this list. Do not do any of these things.

- **DO NOT use `ol.layer.Heatmap`.** It's a density heatmap, not a value heatmap. It makes everything look like a blob centered on point clusters. That is wrong.
- **DO NOT render points, circles, or dots on the client to simulate a heatmap.** It looks terrible, performs badly, and doesn't produce a smooth gradient.
- **DO NOT render hexagon vector features on the client.** The whole point is that the user should NOT see geometric shapes.
- **DO NOT write custom WebGL shaders.** Too complex, too fragile, not needed.
- **DO NOT load all 47,000 H3 cells as GeoJSON.** That's a huge download and defeats the purpose of tiles.
- **DO NOT use canvas drawing or SVG to manually render hex shapes in the browser.**
- **DO NOT show score on hover.** The map is passive visual context. All interaction goes through the pin.
- **DO NOT show DeSO codes anywhere in the user-facing UI.**

**DO:**
- Generate PNG tiles server-side with Python
- Load them in OpenLayers as a standard XYZ tile layer
- Drop a pin on click, fetch data from a simple API endpoint
- Use PostGIS `ST_Contains` to find which DeSO a pin falls in
- Use Photon for geocoding (free, no API key)