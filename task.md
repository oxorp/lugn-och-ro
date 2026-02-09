# TASK: Build DeSO 2018→2025 Crosswalk Table

## Context

SCB switched from DeSO 2018 (5,984 areas) to DeSO 2025 (6,160 areas). All historical data (2019-2023) uses old codes. Our database uses new codes. Without a mapping between them, 5 years of SCB data is unusable.

This is a **blocker** for all historical data ingestion. Build it first.

---

## Step 1: Load DeSO 2018 Boundaries

### 1.1 Fetch from SCB WFS

SCB serves both boundary sets via WFS. We already have DeSO 2025 in `deso_areas`. Now load DeSO 2018:

```bash
php artisan import:deso-2018-boundaries
```

The command should:

1. Fetch DeSO 2018 geometries from SCB WFS:
```
https://geodata.scb.se/geoserver/stat/wfs?service=WFS&version=1.1.0&request=GetFeature&typeName=stat:DeSO&outputFormat=application/json&srsName=EPSG:4326
```

Note: The layer name might be `stat:DeSO` (the 2018 version) vs `stat:DeSO_2025` or similar. Check what's available:
```
https://geodata.scb.se/geoserver/stat/wfs?service=WFS&version=1.1.0&request=GetCapabilities
```

2. Store in a new table `deso_areas_2018`:

```php
Schema::create('deso_areas_2018', function (Blueprint $table) {
    $table->id();
    $table->string('deso_code', 10)->unique()->index();
    $table->string('deso_name')->nullable();
    $table->string('kommun_code', 4)->nullable();
    $table->string('kommun_name')->nullable();
    $table->timestamps();
});

DB::statement("SELECT AddGeometryColumn('public', 'deso_areas_2018', 'geom', 4326, 'MULTIPOLYGON', 2)");
DB::statement("CREATE INDEX deso_areas_2018_geom_idx ON deso_areas_2018 USING GIST (geom)");
```

3. Verify: should get exactly 5,984 areas.

### 1.2 Alternative: SCB May Publish Both in the Same WFS

Check if the WFS has separate layers for 2018 and 2025. If not, check:
- https://www.scb.se/hitta-statistik/regional-statistik-och-kartor/regionala-indelningar/deso---demografiska-statistikomraden/
- SCB geodata portal: https://www.scb.se/vara-tjanster/oppna-data/oppna-geodata/

If the 2018 boundaries aren't available via WFS anymore, check if they're downloadable as a shapefile/GeoPackage.

---

## Step 2: Compute the Crosswalk

### 2.1 Migration

```php
Schema::create('deso_crosswalk', function (Blueprint $table) {
    $table->id();
    $table->string('old_code', 10)->index();       // DeSO 2018 code
    $table->string('new_code', 10)->index();        // DeSO 2025 code
    $table->decimal('overlap_fraction', 8, 6);      // 0.000000 to 1.000000 — what % of the OLD area falls in this NEW area
    $table->decimal('reverse_fraction', 8, 6);      // What % of the NEW area comes from this OLD area
    $table->string('mapping_type', 20);             // '1:1', 'split', 'merge', 'partial'
    $table->timestamps();

    $table->unique(['old_code', 'new_code']);
});
```

### 2.2 Spatial Overlap Computation

```bash
php artisan build:deso-crosswalk
```

The command runs a PostGIS spatial join:

```sql
INSERT INTO deso_crosswalk (old_code, new_code, overlap_fraction, reverse_fraction, mapping_type)
SELECT
    old.deso_code as old_code,
    new.deso_code as new_code,
    -- What fraction of the OLD area overlaps with this NEW area
    ST_Area(ST_Intersection(old.geom, new.geom)) / NULLIF(ST_Area(old.geom), 0) as overlap_fraction,
    -- What fraction of the NEW area comes from this OLD area
    ST_Area(ST_Intersection(old.geom, new.geom)) / NULLIF(ST_Area(new.geom), 0) as reverse_fraction,
    CASE
        -- 1:1 if >95% overlap in both directions
        WHEN ST_Area(ST_Intersection(old.geom, new.geom)) / NULLIF(ST_Area(old.geom), 0) > 0.95
         AND ST_Area(ST_Intersection(old.geom, new.geom)) / NULLIF(ST_Area(new.geom), 0) > 0.95
        THEN '1:1'
        -- Split if one old area maps to multiple new areas
        WHEN ST_Area(ST_Intersection(old.geom, new.geom)) / NULLIF(ST_Area(old.geom), 0) < 0.95
        THEN 'split'
        -- Merge if multiple old areas map to one new area
        WHEN ST_Area(ST_Intersection(old.geom, new.geom)) / NULLIF(ST_Area(new.geom), 0) < 0.95
        THEN 'merge'
        ELSE 'partial'
    END as mapping_type
FROM deso_areas_2018 old
JOIN deso_areas new ON ST_Intersects(old.geom, new.geom)
WHERE ST_Area(ST_Intersection(old.geom, new.geom)) / NULLIF(ST_Area(old.geom), 0) > 0.01;
-- Filter out trivial overlaps (<1%) from edge touching
```

### 2.3 Verify the Crosswalk

```sql
-- Total mappings
SELECT mapping_type, COUNT(*) FROM deso_crosswalk GROUP BY mapping_type;
-- Expect: ~5,800+ are 1:1, ~150-300 are split/merge/partial

-- Every old code should map to at least one new code
SELECT COUNT(DISTINCT old_code) FROM deso_crosswalk;
-- Should be 5,984

-- Every new code should map from at least one old code
SELECT COUNT(DISTINCT new_code) FROM deso_crosswalk;
-- Should be 6,160

-- Overlap fractions for old codes should sum to ~1.0
SELECT old_code, SUM(overlap_fraction) as total
FROM deso_crosswalk
GROUP BY old_code
HAVING ABS(SUM(overlap_fraction) - 1.0) > 0.05
ORDER BY ABS(SUM(overlap_fraction) - 1.0) DESC;
-- Should return very few rows (edge cases only)

-- Check a known split area (find one)
SELECT * FROM deso_crosswalk WHERE mapping_type = 'split' LIMIT 10;
```

---

## Step 3: Value Redistribution Functions

### 3.1 Service Class

Create `app/Services/CrosswalkService.php`:

```php
class CrosswalkService
{
    /**
     * Map a historical value from an old DeSO code to new DeSO code(s).
     *
     * For rate/percentage indicators (income, employment rate, education %):
     *   - 1:1 mappings: use the value directly
     *   - Split mappings: assign the same rate to all child areas
     *     (A DeSO with 75% employment that splits in two → both children get 75%)
     *
     * For count indicators (population):
     *   - 1:1 mappings: use the value directly
     *   - Split mappings: distribute proportionally by overlap area
     *     (A DeSO with 2,000 people that splits 60/40 → 1,200 + 800)
     */
    public function mapOldToNew(string $oldCode, float $rawValue, string $unit): array
    {
        $mappings = DeSoCrosswalk::where('old_code', $oldCode)->get();

        if ($mappings->isEmpty()) {
            Log::warning("No crosswalk mapping for old DeSO: {$oldCode}");
            return [];
        }

        $results = [];
        foreach ($mappings as $mapping) {
            if ($unit === 'percent' || $unit === 'SEK' || $unit === 'per_1000') {
                // Rates: same value for all children
                $results[$mapping->new_code] = $rawValue;
            } else {
                // Counts: distribute by area overlap
                $results[$mapping->new_code] = $rawValue * $mapping->overlap_fraction;
            }
        }

        return $results; // [new_code => value, ...]
    }

    /**
     * Bulk map: given [old_code => value], return [new_code => value].
     * For split areas with rates, all children get the parent's rate.
     * For split areas with counts, children get proportional shares.
     */
    public function bulkMapOldToNew(array $oldValues, string $unit): array
    {
        $crosswalk = DeSoCrosswalk::whereIn('old_code', array_keys($oldValues))
            ->get()
            ->groupBy('old_code');

        $newValues = [];
        foreach ($oldValues as $oldCode => $rawValue) {
            $mappings = $crosswalk->get($oldCode, collect());

            if ($mappings->isEmpty()) {
                continue; // Skip unmapped codes
            }

            foreach ($mappings as $mapping) {
                $newCode = $mapping->new_code;
                $mappedValue = ($unit === 'percent' || $unit === 'SEK' || $unit === 'per_1000')
                    ? $rawValue
                    : $rawValue * $mapping->overlap_fraction;

                // If multiple old areas contribute to one new area (merge case),
                // use area-weighted average for rates, or sum for counts
                if (isset($newValues[$newCode])) {
                    if ($unit === 'percent' || $unit === 'SEK' || $unit === 'per_1000') {
                        // Weighted average by reverse_fraction
                        $newValues[$newCode] = $newValues[$newCode] + $mappedValue * $mapping->reverse_fraction;
                    } else {
                        $newValues[$newCode] += $mappedValue;
                    }
                } else {
                    $newValues[$newCode] = $mappedValue;
                }
            }
        }

        return $newValues;
    }
}
```

**Note:** The weighted average for merged rates is an approximation. Ideally we'd use population-weighted averaging, but population itself is one of the indicators we're backfilling. For the first pass, area-weighted is good enough — the merge cases are few (~1-3% of areas).

---

## Step 4: Verify with Real Data

Pull one historical indicator through the crosswalk and sanity check:

```bash
# 1. Fetch 2022 median_income using old DeSO codes from SCB
php artisan ingest:scb --indicator=median_income --year=2022 --use-old-deso

# 2. Check: Danderyd old DeSO codes should show high income
SELECT old_code, raw_value FROM temp_old_values WHERE old_code LIKE '0162%' ORDER BY raw_value DESC LIMIT 5;

# 3. Map through crosswalk
# 4. Check: corresponding new DeSO codes should have the same values
SELECT new_code, mapped_value FROM mapped_values WHERE new_code LIKE '0162%' ORDER BY mapped_value DESC LIMIT 5;
```

---

## Verification

- [ ] `deso_areas_2018` table has exactly **5,984** rows with geometries
- [ ] `deso_crosswalk` table has mappings for all 5,984 old codes and all 6,160 new codes
- [ ] ~90%+ of mappings are type `1:1`
- [ ] Overlap fractions per old code sum to ~1.0 (within 5% tolerance)
- [ ] `CrosswalkService::bulkMapOldToNew()` correctly handles rate indicators (same value) and count indicators (proportional split)
- [ ] A test run with 2022 median_income produces sensible values for known areas (Danderyd high, etc.)

---

## What NOT to Do

- **DO NOT delete the 2018 boundary table after building the crosswalk.** Keep it for debugging and potential re-computation.
- **DO NOT use population weighting for the first version.** Area-weighted is good enough for the ~5-10% of areas that aren't 1:1. We can refine later.
- **DO NOT assume code format similarity means geographic similarity.** Two codes that look alike might map to completely different areas after the reform. Trust the geometry, not the code pattern.
- **DO NOT filter out small overlaps too aggressively.** The 1% threshold in the query filters edge-touching artifacts, but some legitimate narrow areas might have 5-10% overlaps that matter.