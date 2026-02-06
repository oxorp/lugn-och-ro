# TASK: Project Bootstrap + DeSO Map Visualization

## Context

Read `data_pipeline_specification.md` in this repo for full business context. In short: we're building a Swedish real estate prediction platform that scores neighborhoods using public data (crime, demographics, schools, debt). This task is the **first milestone** — get the infrastructure running and display all 6,160 DeSO areas on an interactive map.

## Stack

- **Backend:** Laravel 11 + PHP 8.3
- **Frontend:** Inertia.js + React 18 + TypeScript
- **UI:** shadcn/ui + Tailwind CSS 4
- **Map:** OpenLayers (NOT Mapbox, NOT Leaflet, NOT Deck.gl — use OpenLayers)
- **Database:** PostgreSQL 16 + PostGIS 3.4
- **Containerization:** Docker (Laravel Sail or custom docker-compose)

## Goal

By the end of this task, we should have:
1. A working Docker environment with PostgreSQL/PostGIS
2. Laravel + Inertia + React + TypeScript + Tailwind + shadcn/ui fully wired
3. An Artisan command that downloads DeSO boundary data from SCB and imports it into PostGIS
4. A full-page OpenLayers map that renders all 6,160 DeSO polygons over Sweden
5. Clickable DeSOs that show a sidebar/popup with basic info (DeSO code, name, kommun, län)

---

## Step 1: Docker Environment

### 1.1 docker-compose.yml

Set up a docker-compose with at minimum these services:

- **app**: PHP 8.3 + required extensions (pdo_pgsql, gd, zip, etc.)
- **postgres**: PostgreSQL 16 with PostGIS extension
- **node**: For Vite dev server (or run it in the app container)
- **redis**: For queue/cache (optional but set it up now)

The critical part: **PostgreSQL must have PostGIS enabled.** Use the `postgis/postgis:16-3.4` image. In the database init, run:

```sql
CREATE EXTENSION IF NOT EXISTS postgis;
```

If using Laravel Sail, note that the default Sail PostgreSQL image does NOT include PostGIS. You need to either:
- Use a custom Dockerfile for the postgres service based on `postgis/postgis:16-3.4`, OR
- Use a plain docker-compose (preferred — Sail adds complexity we don't need)

### 1.2 .env defaults

```
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=realestate
DB_USERNAME=realestate
DB_PASSWORD=secret
```

### 1.3 Verify PostGIS

After `docker-compose up`, confirm PostGIS works:

```sql
SELECT PostGIS_Version();
-- Should return something like "3.4 USE_GEOS=1 USE_PROJ=1 USE_STATS=1"
```

---

## Step 2: Laravel + Inertia + React + TypeScript

### 2.1 Fresh Laravel Install

Start with a fresh Laravel 11 project (if not already initialized).

### 2.2 Install Inertia

Server-side:
```bash
composer require inertiajs/inertia-laravel
```

Publish the middleware, set up the root Blade template (`app.blade.php`) with the `@inertia` directive.

Client-side:
```bash
npm install @inertiajs/react
```

Configure Vite to resolve `.tsx` files. Set up `resources/js/app.tsx` with `createInertiaApp()`.

### 2.3 Install React + TypeScript

```bash
npm install react react-dom
npm install -D @types/react @types/react-dom typescript @vitejs/plugin-react
```

Set up `tsconfig.json` with strict mode. Make sure Vite config uses the React plugin.

### 2.4 Install Tailwind CSS

```bash
npm install -D tailwindcss @tailwindcss/vite
```

Add the Tailwind Vite plugin. Import Tailwind in your main CSS file:

```css
@import "tailwindcss";
```

### 2.5 Install shadcn/ui

Initialize shadcn/ui for React:

```bash
npx shadcn@latest init
```

Pick the defaults that make sense (New York style, zinc base color, CSS variables = yes). Install a few starter components we'll need:

```bash
npx shadcn@latest add card button sheet scroll-area badge separator
```

### 2.6 Verify

Create a simple test route + Inertia page to confirm the full chain works:
- Laravel route returns `Inertia::render('Test', ['message' => 'Hello'])`
- React page at `resources/js/Pages/Test.tsx` renders the message with a shadcn Card
- Tailwind classes work
- Hot reload works via Vite

---

## Step 3: Database Schema for DeSO

### 3.1 Migration

Create a migration for the `deso_areas` table:

```php
Schema::create('deso_areas', function (Blueprint $table) {
    $table->id();
    $table->string('deso_code', 10)->unique()->index();   // e.g., "0114A0010"
    $table->string('deso_name')->nullable();               // DeSO name if available
    $table->string('kommun_code', 4)->index();             // e.g., "0114"
    $table->string('kommun_name')->nullable();
    $table->string('lan_code', 2)->index();                // e.g., "01"
    $table->string('lan_name')->nullable();
    $table->float('area_km2')->nullable();
    $table->integer('population')->nullable();
    $table->timestamps();
});

// Add PostGIS geometry column
DB::statement("SELECT AddGeometryColumn('public', 'deso_areas', 'geom', 4326, 'MULTIPOLYGON', 2)");
DB::statement("CREATE INDEX deso_areas_geom_idx ON deso_areas USING GIST (geom)");
```

**Important:** The geometry column uses SRID 4326 (WGS84 / lat-lng). SCB serves data in SWEREF99TM (EPSG:3006). The import command must reproject.

### 3.2 Model

```php
// app/Models/DesoArea.php
class DesoArea extends Model
{
    protected $fillable = [
        'deso_code', 'deso_name', 'kommun_code', 'kommun_name',
        'lan_code', 'lan_name', 'area_km2', 'population',
    ];

    // geom is handled via raw queries, not Eloquent casts
}
```

---

## Step 4: DeSO Import Command

### 4.1 Data Source

SCB publishes DeSO boundaries via a WFS (Web Feature Service) on GeoServer:

**WFS endpoint:** `https://geodata.scb.se/geoserver/stat/wfs`

To download DeSO 2025 as GeoJSON:

```
https://geodata.scb.se/geoserver/stat/wfs?service=WFS&version=1.1.0&request=GetFeature&typeName=stat:DeSO.2025&outputFormat=application/json&srsName=EPSG:4326
```

Key parameters:
- `typeName=stat:DeSO.2025` — the DeSO 2025 layer (use this, not 2018)
- `outputFormat=application/json` — returns GeoJSON
- `srsName=EPSG:4326` — request coordinates in WGS84 (lat/lng) so we don't need to reproject

**Fallback:** If the WFS request fails or is too slow (the full dataset is ~6,160 features with detailed polygons), try:
- `typeName=stat:DeSO.2018` as fallback (older version, 5,984 features)
- Or download as GeoPackage/Shapefile and process locally

**Note on size:** The GeoJSON for all of Sweden's DeSOs will be ~50-150MB. This is fine for a one-time import. The command should download it once and cache it in `storage/app/geodata/`.

### 4.2 Artisan Command

Create `app/Console/Commands/ImportDesoAreas.php`:

```bash
php artisan make:command ImportDesoAreas
```

The command should:

1. **Download** the GeoJSON from SCB WFS (if not already cached in `storage/app/geodata/deso_2025.geojson`)
2. **Parse** the GeoJSON file — it's a FeatureCollection where each Feature has:
   - `geometry`: MultiPolygon or Polygon
   - `properties`: includes `deso` (the DeSO code), `kommun` (kommun code), `lan` (län code), and possibly `kommunnamn`, `lannamn`
3. **Insert** each feature into the `deso_areas` table, storing the geometry via ST_GeomFromGeoJSON()
4. **Compute** area_km2 using ST_Area with geography cast

Example insert logic:

```php
DB::statement("
    INSERT INTO deso_areas (deso_code, kommun_code, lan_code, geom, area_km2, created_at, updated_at)
    VALUES (
        :deso_code,
        :kommun_code,
        :lan_code,
        ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326),
        ST_Area(ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326)::geography) / 1000000,
        NOW(),
        NOW()
    )
", [
    'deso_code' => $feature['properties']['deso'],
    'kommun_code' => $feature['properties']['kommun'],
    'lan_code' => $feature['properties']['lan'],
    'geojson' => json_encode($feature['geometry']),
]);
```

**Important considerations:**
- The GeoJSON file might be large. Parse it in a streaming fashion or chunk the inserts (batch of 100).
- Wrap the whole import in a transaction.
- Add a `--fresh` flag that truncates the table before importing.
- Log progress: "Imported 1000/6160 DeSO areas..."
- The property names in the GeoJSON may vary. Inspect the actual response to confirm field names. Common possibilities: `deso`, `Deso`, `DESO`, `deso_kod`, etc. The command should handle this gracefully.
- kommun_name and lan_name might not be in the GeoJSON properties. If not, create a separate lookup or leave nullable for now.

### 4.3 Run & Verify

```bash
php artisan import:deso-areas
```

After import, verify:

```sql
SELECT COUNT(*) FROM deso_areas;
-- Should be ~6,160

SELECT deso_code, kommun_code, ST_AsText(ST_Centroid(geom)) FROM deso_areas LIMIT 5;
-- Should show WGS84 coordinates in Sweden (lat ~56-69, lng ~11-24)
```

---

## Step 5: Map Page with OpenLayers

### 5.1 Install OpenLayers

```bash
npm install ol
npm install -D @types/ol   # if available, otherwise OL ships its own types
```

### 5.2 Create the Map Page

**Route:** `routes/web.php`

```php
Route::get('/', [MapController::class, 'index'])->name('map');
```

**Controller:** `app/Http/Controllers/MapController.php`

The controller returns an Inertia page. It does NOT pass all 6,160 DeSO geometries as page props — that would be way too much data. Instead, the React frontend fetches DeSO GeoJSON from a dedicated API endpoint.

```php
public function index()
{
    return Inertia::render('Map', [
        'initialCenter' => [62.0, 15.0],  // Center of Sweden
        'initialZoom' => 5,
    ]);
}
```

### 5.3 GeoJSON API Endpoint

Create an API endpoint that serves DeSO geometries as GeoJSON:

**Route:** `routes/web.php` (or `api.php`)

```php
Route::get('/api/deso/geojson', [DesoController::class, 'geojson'])->name('deso.geojson');
```

**Controller:** `app/Http/Controllers/DesoController.php`

This endpoint queries PostGIS and returns a GeoJSON FeatureCollection. **Critical:** simplify the geometries for map rendering — full-resolution polygons are too heavy. Use `ST_Simplify` to reduce vertex count.

```php
public function geojson(Request $request)
{
    // Simplify geometries based on zoom level
    // tolerance ~0.001 for overview, ~0.0001 for zoomed in
    $tolerance = $request->float('tolerance', 0.005);

    $features = DB::select("
        SELECT
            deso_code,
            deso_name,
            kommun_code,
            kommun_name,
            lan_code,
            lan_name,
            area_km2,
            ST_AsGeoJSON(ST_Simplify(geom, ?)) as geometry
        FROM deso_areas
        WHERE geom IS NOT NULL
    ", [$tolerance]);

    $geojson = [
        'type' => 'FeatureCollection',
        'features' => collect($features)->map(fn ($f) => [
            'type' => 'Feature',
            'geometry' => json_decode($f->geometry),
            'properties' => [
                'deso_code' => $f->deso_code,
                'deso_name' => $f->deso_name,
                'kommun_code' => $f->kommun_code,
                'kommun_name' => $f->kommun_name,
                'lan_code' => $f->lan_code,
                'lan_name' => $f->lan_name,
                'area_km2' => $f->area_km2,
            ],
        ])->all(),
    ];

    return response()->json($geojson)
        ->header('Cache-Control', 'public, max-age=86400');  // Cache 24h
}
```

**Performance note:** 6,160 simplified polygons as GeoJSON will be ~5-15MB depending on simplification tolerance. This is fine for an initial load. Later optimization: serve as vector tiles (MVT) for zoom-dependent loading — but that's a future task, not this one.

### 5.4 React Map Component

Create `resources/js/Pages/Map.tsx` and `resources/js/Components/DesoMap.tsx`.

The map should:

1. **Initialize OpenLayers** with an OSM base layer, centered on Sweden
2. **Fetch** the DeSO GeoJSON from `/api/deso/geojson` on mount
3. **Render** DeSO polygons as a vector layer with:
   - Semi-transparent fill (e.g., `rgba(30, 80, 160, 0.15)`)
   - Visible border stroke (e.g., `rgba(30, 80, 160, 0.5)`, 1px)
   - Hover effect: highlight fill on mouseover
4. **Click handler:** When a DeSO is clicked, show its info in a sidebar panel (use shadcn Sheet or a custom panel)
5. **Responsive layout:** Map takes full viewport height, sidebar overlays or pushes from the right

OpenLayers specifics:
- Use `ol/layer/Vector` with `ol/source/Vector` and `ol/format/GeoJSON`
- Projection: OpenLayers uses EPSG:3857 (Web Mercator) for display. The GeoJSON is in EPSG:4326. OL handles the reprojection automatically when you load GeoJSON — just make sure `dataProjection: 'EPSG:4326'` and `featureProjection: 'EPSG:3857'` are set in the GeoJSON format.
- For hover/click: use `ol/interaction/Select` or manual `map.on('pointermove')` / `map.on('click')` with `map.forEachFeatureAtPixel()`
- Style the selected feature differently (brighter fill, thicker stroke)

### 5.5 Sidebar / Info Panel

When a DeSO is clicked, show a panel with:

- DeSO code
- DeSO name (if available)
- Kommun name + code
- Län name + code
- Area (km²)

Use a shadcn `Sheet` component that slides in from the right, or a fixed sidebar. Keep it simple — this will later expand to show the full score breakdown.

### 5.6 Layout

Create a layout component (`resources/js/Layouts/AppLayout.tsx`) that provides:

- A thin top navbar with the app name ("Swedish Real Estate Platform" or whatever)
- Full-height content area below

The map page should be full-bleed (no padding), map fills the entire content area.

---

## Step 6: Verify Everything Works

### Checklist

- [ ] `docker-compose up` starts all services cleanly
- [ ] `php artisan migrate` creates the `deso_areas` table with PostGIS geometry
- [ ] `SELECT PostGIS_Version()` returns a valid version
- [ ] `php artisan import:deso-areas` downloads and imports ~6,160 DeSO areas
- [ ] `SELECT COUNT(*) FROM deso_areas` returns ~6,160
- [ ] Visiting `http://localhost` (or your configured URL) shows the map
- [ ] Map displays Sweden with an OSM base layer
- [ ] All DeSO polygons are visible as a semi-transparent overlay
- [ ] Hovering over a DeSO highlights it
- [ ] Clicking a DeSO opens a sidebar with the area's info
- [ ] shadcn components render correctly (check the Sheet, Card, Badge styles)
- [ ] Tailwind utility classes work in the React components
- [ ] Hot reload works when editing React files

---

## Notes for the Agent

### What NOT to do
- Don't use Mapbox GL JS — we're using OpenLayers (open source, no API key needed)
- Don't use Leaflet — OpenLayers is more powerful for vector data at this scale
- Don't use Deck.gl — overkill for this stage
- Don't pass all DeSO geometries as Inertia page props — fetch via API
- Don't try to install h3-pg or do any H3 processing yet — that's a future task
- Don't set up any data ingestion beyond DeSO boundaries — crime, demographics, etc. come later
- Don't over-engineer the frontend — we want a working map, not a design system

### What to prioritize
- Get Docker + PostGIS working first — this is the foundation
- Get the DeSO import working and verified in the database before touching the frontend
- The map is the deliverable — spend time making sure it loads, renders, and responds to interaction
- If the SCB WFS is slow or returns errors, cache the downloaded file and work from the cache

### Coordinate reference systems
- SCB native CRS: SWEREF99TM (EPSG:3006)
- Our database CRS: WGS84 (EPSG:4326) — request `srsName=EPSG:4326` from the WFS
- OpenLayers display CRS: Web Mercator (EPSG:3857) — OL reprojects automatically from 4326

### DeSO 2025 vs 2018
- DeSO was updated in 2025 (6,160 areas, up from 5,984). Use DeSO 2025.
- WFS layer name: `stat:DeSO.2025`
- If 2025 isn't available on the WFS, fall back to `stat:DeSO.2018`
- Check the WFS capabilities first: `https://geodata.scb.se/geoserver/stat/wfs?service=WFS&version=1.1.0&request=GetCapabilities`

### Reference
- SCB open geodata: https://www.scb.se/en/services/open-data-api/open-geodata/
- DeSO geodata page: https://www.scb.se/en/services/open-data-api/open-geodata/open-data-for-deso--demographic-statistical-areas/
- WFS endpoint: https://geodata.scb.se/geoserver/stat/wfs
- WMS endpoint: https://geodata.scb.se/geoserver/stat/wms
- Full pipeline spec: `data_pipeline_specification.md` in this repo