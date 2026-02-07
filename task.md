# TASK: POI (Point of Interest) Ingestion System

## Context

The platform scores neighborhoods based on socioeconomic data (SCB) and school quality (Skolverket). The next dimension to add is **physical amenities and services** — what's actually on the ground in each area. This is the "livability" layer: grocery stores, healthcare, restaurants, gyms, and also negative signals like gambling venues and pawn shops.

This is fundamentally different from previous data sources:
- SCB gives us DeSO-level aggregates. Skolverket gives us school points that we aggregate to DeSO.
- POIs are **thousands of points** scraped from multiple external APIs that need classification, deduplication, DeSO assignment, per-capita normalization, and urbanity-stratified ranking.

The system must be generic — adding a new POI category (say, EV charging stations next year) should require only a config entry and possibly a new scraping adapter, not new tables or new aggregation logic.

**Prerequisite:** The urbanity stratification task must be complete. POI indicators use `normalization_scope = 'urbanity_stratified'` so a rural DeSO's single grocery store isn't penalized against Södermalm's fifteen.

## Goals

1. Generic `pois` table that holds all point-of-interest data regardless of source
2. Ingestion adapters for Overpass (OSM) and Google Places
3. Spatial join to assign each POI to its DeSO
4. Per-capita density computation per DeSO per category
5. Catchment-based access metrics (what's reachable, not just what's inside the boundary)
6. New indicators with urbanity-stratified normalization
7. Periodic scraping on a schedule

---

## Step 1: Database Schema

### 1.1 POIs Table

```php
Schema::create('pois', function (Blueprint $table) {
    $table->id();
    $table->string('external_id', 100)->nullable();      // Source's unique ID (OSM node ID, Google place_id)
    $table->string('source', 40);                         // 'osm', 'google_places', 'systembolaget', 'manual'
    $table->string('category', 60)->index();              // 'grocery', 'healthcare', 'restaurant', 'gym', etc.
    $table->string('subcategory', 60)->nullable();        // 'premium_grocery', 'fast_food', 'kebab', etc.
    $table->string('name', 255)->nullable();
    $table->decimal('lat', 10, 7);
    $table->decimal('lng', 10, 7);
    $table->string('deso_code', 10)->nullable()->index(); // Resolved via PostGIS ST_Contains
    $table->string('municipality_code', 4)->nullable();
    $table->jsonb('tags')->nullable();                    // Raw source tags/attributes
    $table->jsonb('metadata')->nullable();                // Our enrichments (chain, brand, size, etc.)
    $table->string('status', 20)->default('active');      // 'active', 'closed', 'unverified'
    $table->timestamp('last_verified_at')->nullable();    // When source last confirmed this exists
    $table->timestamps();

    $table->unique(['source', 'external_id']);
    $table->index(['category', 'status']);
    $table->index(['deso_code', 'category']);
});

// Spatial column + index
DB::statement("SELECT AddGeometryColumn('public', 'pois', 'geom', 4326, 'POINT', 2)");
DB::statement("CREATE INDEX pois_geom_idx ON pois USING GIST (geom)");
```

### 1.2 POI Categories Configuration Table

Instead of hardcoding categories, store the category definitions:

```php
Schema::create('poi_categories', function (Blueprint $table) {
    $table->id();
    $table->string('slug', 60)->unique();                // 'grocery', 'healthcare', etc.
    $table->string('name');                               // 'Grocery Stores'
    $table->string('indicator_slug', 80)->nullable();     // Links to indicators table: 'grocery_density'
    $table->enum('signal', ['positive', 'negative', 'neutral'])->default('neutral');
    $table->json('osm_tags')->nullable();                 // OSM tag filter: {"shop": ["supermarket", "convenience"]}
    $table->json('google_types')->nullable();             // Google Places types: ["supermarket", "grocery_or_supermarket"]
    $table->decimal('catchment_km', 5, 2)->default(1.50); // Default catchment radius for access metric
    $table->boolean('is_active')->default(true);
    $table->text('description')->nullable();
    $table->timestamps();
});
```

### 1.3 Seed POI Categories

| slug | name | signal | osm_tags | catchment_km | indicator_slug |
|---|---|---|---|---|---|
| `grocery` | Grocery Stores | positive | `{"shop": ["supermarket", "convenience", "greengrocer"]}` | 1.5 | `grocery_density` |
| `healthcare` | Healthcare Facilities | positive | `{"amenity": ["hospital", "clinic", "doctors", "pharmacy"]}` | 3.0 | `healthcare_density` |
| `restaurant` | Restaurants & Cafés | positive | `{"amenity": ["restaurant", "cafe"]}` | 1.0 | `restaurant_density` |
| `fitness` | Gyms & Fitness | positive | `{"leisure": ["fitness_centre", "sports_centre"], "sport": ["padel"]}` | 2.0 | `fitness_density` |
| `school_grundskola` | Primary Schools | positive | *Already handled by Skolverket* | — | *skip — use existing school indicators* |
| `gambling` | Gambling Venues | negative | `{"shop": ["bookmaker", "lottery"], "amenity": ["gambling", "casino"]}` | — | `gambling_density` |
| `pawn_shop` | Pawn Shops | negative | `{"shop": ["pawnbroker"]}` | — | `pawn_shop_density` |
| `fast_food_late` | Late-Night Fast Food | negative | `{"amenity": ["fast_food"]}` | — | `fast_food_density` |
| `premium_grocery` | Premium Grocery | positive | *Google Places + manual* | 2.0 | `premium_grocery_density` |
| `public_transport_stop` | Public Transport Stops | positive | `{"highway": ["bus_stop"], "railway": ["station", "halt", "tram_stop"]}` | 1.0 | `transit_stop_density` |

**Note:** These are starting categories. The system is designed so adding a new row to `poi_categories` + a scraping run is all that's needed for a new data dimension.

---

## Step 2: POI Ingestion — Overpass API (OSM)

### 2.1 OverpassService

Create `app/Services/OverpassService.php`:

```php
class OverpassService
{
    private string $endpoint = 'https://overpass-api.de/api/interpreter';
    private int $timeout = 120;

    /**
     * Query OSM data for Sweden by tags
     * @param array $tags e.g. ['shop' => ['supermarket', 'convenience']]
     * @return Collection of ['lat', 'lng', 'osm_id', 'osm_type', 'name', 'tags']
     */
    public function querySweden(array $tags): Collection
    {
        $tagFilters = $this->buildTagFilters($tags);

        $query = "[out:json][timeout:{$this->timeout}];
            area['ISO3166-1'='SE']->.sweden;
            (
                {$tagFilters}
            );
            out center;";

        $response = Http::timeout($this->timeout + 30)
            ->asForm()
            ->post($this->endpoint, ['data' => $query]);

        if (!$response->successful()) {
            throw new \RuntimeException("Overpass query failed: " . $response->status());
        }

        return collect($response->json()['elements'])->map(fn ($el) => [
            'lat' => $el['lat'] ?? $el['center']['lat'] ?? null,
            'lng' => $el['lon'] ?? $el['center']['lon'] ?? null,
            'osm_id' => $el['id'],
            'osm_type' => $el['type'],  // node, way, relation
            'name' => $el['tags']['name'] ?? null,
            'tags' => $el['tags'] ?? [],
        ])->filter(fn ($el) => $el['lat'] !== null && $el['lng'] !== null);
    }

    private function buildTagFilters(array $tags): string
    {
        $filters = [];
        foreach ($tags as $key => $values) {
            $valueRegex = implode('|', $values);
            $filters[] = "nwr[\"{$key}\"~\"{$valueRegex}\"](area.sweden);";
        }
        return implode("\n", $filters);
    }
}
```

### 2.2 Ingestion Command

```bash
php artisan ingest:pois [--source=osm] [--category=grocery] [--all]
```

```php
class IngestPois extends Command
{
    protected $signature = 'ingest:pois
        {--source=osm : Data source (osm, google_places)}
        {--category= : Specific category slug, or omit for all}
        {--all : Process all active categories}';

    public function handle(OverpassService $overpass)
    {
        $categories = $this->getCategories();

        foreach ($categories as $category) {
            $this->info("Ingesting: {$category->name} from {$this->option('source')}");

            $points = match($this->option('source')) {
                'osm' => $overpass->querySweden($category->osm_tags),
                'google_places' => $this->fetchGooglePlaces($category),
                default => throw new \InvalidArgumentException("Unknown source"),
            };

            $this->info("  Found {$points->count()} points");

            $created = 0;
            $updated = 0;

            foreach ($points as $point) {
                $poi = Poi::updateOrCreate(
                    [
                        'source' => $this->option('source'),
                        'external_id' => $this->option('source') === 'osm'
                            ? "osm_{$point['osm_type']}_{$point['osm_id']}"
                            : $point['place_id'],
                    ],
                    [
                        'category' => $category->slug,
                        'name' => $point['name'],
                        'lat' => $point['lat'],
                        'lng' => $point['lng'],
                        'tags' => $point['tags'] ?? null,
                        'status' => 'active',
                        'last_verified_at' => now(),
                    ]
                );

                // Set PostGIS geometry
                if ($poi->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }

                DB::statement("
                    UPDATE pois SET geom = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?
                ", [$point['lng'], $point['lat'], $poi->id]);
            }

            $this->info("  Created: {$created}, Updated: {$updated}");
        }

        // After all categories ingested, assign DeSO codes
        $this->call('assign:poi-deso');
    }
}
```

### 2.3 DeSO Assignment Command

```bash
php artisan assign:poi-deso
```

Spatial join — identical pattern to schools:

```php
class AssignPoiDeso extends Command
{
    public function handle()
    {
        $updated = DB::update("
            UPDATE pois p
            SET deso_code = d.deso_code
            FROM deso_areas d
            WHERE ST_Contains(d.geom, p.geom)
              AND p.geom IS NOT NULL
              AND p.deso_code IS NULL
        ");

        $this->info("Assigned DeSO codes to {$updated} POIs");

        // Log any unassigned (offshore, data errors)
        $unassigned = Poi::whereNull('deso_code')->whereNotNull('lat')->count();
        if ($unassigned > 0) {
            $this->warn("{$unassigned} POIs could not be assigned to a DeSO (coordinates outside Sweden boundaries)");
        }
    }
}
```

### 2.4 Rate Limiting & Batching

Overpass API has usage limits. For all of Sweden's POIs across all categories:
- Run one category at a time (don't parallelize Overpass queries)
- Wait 10 seconds between category queries
- Total time for ~8 categories: a few minutes (each query returns in 10-60 seconds)

Google Places API is metered (paid). For the initial build, **focus on OSM**. Google Places is a future enhancement for categories where OSM coverage is weak (premium grocery, padel courts, etc.).

---

## Step 3: Aggregation to DeSO Indicators

### 3.1 Two Types of Metrics

For each POI category, compute two things per DeSO:

**A. Density (count per capita)**
```
grocery_density = count(groceries in DeSO) / (DeSO population / 1000)
```
Unit: per 1,000 residents. This measures "how well-served is this area?"

**B. Catchment access (reachable count)**
```
grocery_access = count(groceries within X km of DeSO centroid) / (DeSO population / 1000)
```
This is better than density because it doesn't penalize boundary DeSOs. A grocery store 100m outside your DeSO boundary still serves you.

Use **catchment access** as the primary indicator, with **density** as a secondary/debug metric.

### 3.2 Aggregation Command

```bash
php artisan aggregate:poi-indicators [--year=2025]
```

```php
class AggregatePoiIndicators extends Command
{
    public function handle()
    {
        $year = $this->option('year') ?? now()->year;
        $categories = PoiCategory::where('is_active', true)
            ->whereNotNull('indicator_slug')
            ->get();

        foreach ($categories as $category) {
            $this->info("Aggregating: {$category->name}");

            $indicator = Indicator::where('slug', $category->indicator_slug)->firstOrFail();

            // Catchment-based: count POIs within catchment_km of each DeSO centroid
            $results = DB::select("
                SELECT
                    d.deso_code,
                    d.population,
                    COUNT(p.id) AS poi_count
                FROM deso_areas d
                LEFT JOIN pois p ON
                    p.category = ?
                    AND p.status = 'active'
                    AND p.geom IS NOT NULL
                    AND ST_DWithin(
                        p.geom::geography,
                        ST_Centroid(d.geom)::geography,
                        ? * 1000  -- catchment in meters
                    )
                GROUP BY d.deso_code, d.population
            ", [$category->slug, $category->catchment_km]);

            $count = 0;
            foreach ($results as $row) {
                if ($row->population > 0) {
                    $rawValue = ($row->poi_count / $row->population) * 1000;
                } else {
                    $rawValue = null;
                }

                IndicatorValue::updateOrCreate(
                    [
                        'deso_code' => $row->deso_code,
                        'indicator_id' => $indicator->id,
                        'year' => $year,
                    ],
                    [
                        'raw_value' => $rawValue,
                    ]
                );
                $count++;
            }

            $this->info("  Stored {$count} indicator values");
        }
    }
}
```

### 3.3 Performance Note

The `ST_DWithin` query with geography cast computes true geodesic distances. For ~6,160 DeSOs × ~10,000 POIs per category, this is manageable but not instant. Expected time: 10-30 seconds per category with a spatial index on `pois.geom`.

If performance is a problem, pre-compute using H3: assign each POI to its H3 res-8 cell, and for each DeSO find all H3 cells within the catchment radius using `h3_grid_disk`. This is much faster for repeated queries.

### 3.4 The Zero Problem

Many DeSOs will have 0 POIs for a given category. This is fine and expected. Store `raw_value = 0` (not NULL). A DeSO with zero gambling venues should get a high normalized score for the `gambling_density` indicator (since it's a negative-direction indicator). NULL means "no data"; 0 means "we checked and there are none."

For rural DeSOs with zero of *everything* within catchment radius: stratified normalization handles this. They're ranked against other rural DeSOs, many of which also have zero. The percentile rank of zero in a group where half the DeSOs are zero is ~0.25-0.50, not 0.00.

---

## Step 4: Indicator Definitions

### 4.1 Seed New Indicators

Add via seeder or migration:

| slug | name | unit | direction | weight | category | normalization_scope |
|---|---|---|---|---|---|---|
| `grocery_density` | Grocery Access | per_1000 | positive | 0.04 | amenities | urbanity_stratified |
| `healthcare_density` | Healthcare Access | per_1000 | positive | 0.03 | amenities | urbanity_stratified |
| `restaurant_density` | Restaurant & Café Density | per_1000 | positive | 0.02 | amenities | urbanity_stratified |
| `fitness_density` | Fitness & Sports Access | per_1000 | positive | 0.02 | amenities | urbanity_stratified |
| `transit_stop_density` | Public Transport Stops | per_1000 | positive | 0.04 | transport | urbanity_stratified |
| `gambling_density` | Gambling Venue Density | per_1000 | negative | 0.02 | amenities | urbanity_stratified |
| `pawn_shop_density` | Pawn Shop Density | per_1000 | negative | 0.01 | amenities | urbanity_stratified |
| `fast_food_density` | Late-Night Fast Food Density | per_1000 | negative | 0.01 | amenities | urbanity_stratified |

**Total new weight: 0.19** — this eats into the unallocated budget.

### 4.2 Updated Weight Budget

| Category | Previous | New |
|---|---|---|
| Income (SCB) | 0.20 | 0.18 |
| Employment (SCB) | 0.10 | 0.08 |
| Education — demographics (SCB) | 0.10 | 0.08 |
| Education — school quality (Skolverket) | 0.25 | 0.22 |
| Amenities — positive (POI) | 0.00 | 0.11 |
| Amenities — negative (POI) | 0.00 | 0.04 |
| Transport (POI) | 0.00 | 0.04 |
| **Unallocated** (crime, debt) | **0.35** | **0.25** |

These weights are initial estimates. The admin dashboard lets you tune without code changes.

---

## Step 5: Deduplication

### 5.1 The Problem

The same grocery store might appear in both OSM and Google Places. Two OSM nodes might represent the same physical location (entrance vs. building centroid). A store might close but remain in OSM for months.

### 5.2 Cross-Source Deduplication

When a POI from Google Places is within 50m of an OSM POI with the same category and a similar name, they're likely the same place. Don't create a duplicate — update the existing record with the additional source info.

```php
// In the ingestion, before creating:
$existing = Poi::where('category', $category->slug)
    ->where('status', 'active')
    ->whereRaw("ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 50)", [$lng, $lat])
    ->first();

if ($existing && $this->namesSimilar($existing->name, $newName)) {
    // Update existing with new source info in metadata
    $existing->update([
        'metadata' => array_merge($existing->metadata ?? [], [
            'also_in' => $source,
            'alt_name' => $newName,
        ]),
        'last_verified_at' => now(),
    ]);
    continue;
}
```

### 5.3 Staleness

POIs that haven't been verified in 6+ months should be flagged. POIs from a source that's been refreshed but where a specific POI didn't appear in the latest scrape should be marked `status = 'unverified'` (the store may have closed).

```php
// After ingestion of a category from a source:
Poi::where('source', $source)
    ->where('category', $category->slug)
    ->where('last_verified_at', '<', now()->subMonths(6))
    ->update(['status' => 'unverified']);
```

Unverified POIs are excluded from indicator aggregation.

---

## Step 6: Google Places Integration (Future / Optional)

### 6.1 When to Use Google Places

OSM coverage in Sweden is good for common categories (grocery, restaurant, healthcare) but weak for:
- Premium/specialty stores (Paradiset, specialty coffee)
- Padel courts and boutique fitness
- Recently opened businesses
- Business hours and ratings

Google Places fills these gaps but costs money (~$32 per 1,000 requests for Nearby Search).

### 6.2 Cost Estimation

For all of Sweden:
- ~290 municipalities × ~5 search queries per category × ~8 categories = ~11,600 API calls
- At $32/1,000 = ~$370 per full scrape
- Monthly: $370/month for comprehensive coverage

This is reasonable for a production service. For development, use OSM only.

### 6.3 Implementation

Create `app/Services/GooglePlacesService.php` with the same interface as OverpassService. The ingestion command already supports `--source=google_places` — it just needs the adapter.

Store the Google API key in `.env`:
```
GOOGLE_PLACES_API_KEY=your_key_here
```

---

## Step 7: POI Map Layer (Optional Enhancement)

### 7.1 Show POIs for Selected DeSO

Same pattern as school markers: when a DeSO is selected, optionally show POI markers on the map.

This is lower priority than getting the indicators right. The POI data's main value is in the composite score, not as map dots. But for the sidebar, listing "3 grocery stores, 2 pharmacies, 1 gym" with names is useful context.

### 7.2 API Endpoint

```php
Route::get('/api/deso/{desoCode}/pois', [DesoController::class, 'pois']);
```

Returns POIs grouped by category for the selected DeSO + catchment area.

---

## Step 8: Pipeline Integration

### 8.1 Full POI Pipeline

```bash
# 1. Scrape POI data from OSM
php artisan ingest:pois --source=osm --all

# 2. Assign DeSO codes (spatial join)
php artisan assign:poi-deso

# 3. Aggregate to DeSO-level indicators
php artisan aggregate:poi-indicators --year=2025

# 4. Normalize (including stratified for POI indicators)
php artisan normalize:indicators --year=2025

# 5. Recompute scores
php artisan compute:scores --year=2025
```

### 8.2 Schedule

```php
// Monthly POI refresh
$schedule->command('ingest:pois --source=osm --all')->monthly();
$schedule->command('assign:poi-deso')->monthly();
$schedule->command('aggregate:poi-indicators')->monthly();
```

### 8.3 Validation Rules

Add to the data quality framework:

| indicator_slug | rule_type | parameters |
|---|---|---|
| grocery_density | completeness | min_coverage_pct: 90 (almost every DeSO should have a value, even if 0) |
| grocery_density | distribution | expected_mean: 0.5–3.0 per 1,000 |
| grocery_density | range | min: 0, max: 50 (sanity) |
| gambling_density | range | min: 0, max: 10 |

---

## Step 9: Verification

### 9.1 Database Checks

```sql
-- POI counts by category
SELECT category, COUNT(*), COUNT(DISTINCT deso_code) AS desos_with_pois
FROM pois
WHERE status = 'active'
GROUP BY category;

-- Expected rough counts for Sweden:
-- grocery: 3,000-5,000
-- restaurant: 10,000-20,000
-- healthcare: 2,000-4,000
-- fitness: 1,000-3,000
-- gambling: 50-200
-- pawn_shop: 20-50

-- Check aggregated indicators
SELECT i.slug, COUNT(iv.id), AVG(iv.raw_value), MIN(iv.raw_value), MAX(iv.raw_value)
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
WHERE i.category = 'amenities' AND iv.year = 2025
GROUP BY i.slug;

-- Sanity: central Stockholm should have high grocery density
SELECT iv.deso_code, da.kommun_name, iv.raw_value AS grocery_per_1000
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
JOIN deso_areas da ON da.deso_code = iv.deso_code
WHERE i.slug = 'grocery_density'
ORDER BY iv.raw_value DESC LIMIT 10;

-- Sanity: rural areas should have low but non-zero values
SELECT iv.deso_code, da.kommun_name, da.urbanity_tier, iv.raw_value
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
JOIN deso_areas da ON da.deso_code = iv.deso_code
WHERE i.slug = 'grocery_density' AND da.urbanity_tier = 'rural'
ORDER BY iv.raw_value DESC LIMIT 10;

-- Check that stratified normalization worked:
-- Rural DeSOs with the best rural grocery access should have high normalized_value
-- even though their raw_value is lower than urban DeSOs
SELECT da.urbanity_tier, AVG(iv.raw_value) AS avg_raw, AVG(iv.normalized_value) AS avg_normalized
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
JOIN deso_areas da ON da.deso_code = iv.deso_code
WHERE i.slug = 'grocery_density'
GROUP BY da.urbanity_tier;
-- Expected: avg_raw differs significantly by tier, avg_normalized is ~0.50 for all tiers
```

### 9.2 Visual Checklist

- [ ] POIs imported from OSM (check counts per category)
- [ ] All POIs assigned to DeSOs (minimal unassigned)
- [ ] Indicator values computed for all POI categories
- [ ] Admin dashboard shows new POI indicators with weights
- [ ] Stratified normalization produces avg_normalized ~0.50 per urbanity tier
- [ ] Composite scores shifted after adding POI indicators
- [ ] Sentinel checks still pass
- [ ] Map colors changed slightly (POIs now contribute to score)
- [ ] Sidebar shows POI-based indicators in the breakdown

---

## Notes for the Agent

### OSM Tag Discovery

The `osm_tags` in the category seeder are starting points. OSM tagging in Sweden is generally good but varies. After the first scrape, inspect the results:
- Are important chains missing? (ICA, Coop, Hemköp for grocery)
- Are irrelevant results included? (a furniture store tagged as "shop" matching your filter)
- Adjust the Overpass queries and re-scrape

### Catchment Radius Tuning

The default catchment radii (1.5 km for grocery, 3 km for healthcare) are urban-centric. Consider making catchment radius also urbanity-stratified: 1.5 km in urban, 5 km in semi-urban, 15 km in rural. This reflects how far people actually travel for services in different contexts.

Alternatively, keep a single catchment and let the stratified normalization handle it. Simpler, and usually good enough.

### What NOT to Do

- Don't show raw POI markers for the entire country on the map (performance disaster, visual noise)
- Don't use Google Places for the initial build (cost, complexity) — OSM first
- Don't create separate tables per POI category — the generic `pois` table handles all types
- Don't skip the zero-value storage — 0 is data, NULL is "we don't know"
- Don't normalize POI indicators nationally — use urbanity_stratified

### What to Prioritize

1. Generic pois table + OSM ingestion for grocery (prove the pattern with one category)
2. DeSO assignment + catchment aggregation
3. Indicator creation + stratified normalization
4. Remaining positive categories (healthcare, restaurant, fitness, transit)
5. Negative categories (gambling, pawn, fast food)
6. Sidebar display of POI indicators
7. Google Places adapter (later, when OSM gaps are identified)
8. POI map markers for selected DeSO (polish)