# TASK: Urbanity Classification & Stratified Normalization

## Context

The platform currently normalizes every indicator by ranking all ~6,160 DeSOs against each other nationally. This works for socioeconomic indicators (income, employment, education) where a national comparison is meaningful — low income is low income regardless of whether you're in Stockholm or Jokkmokk.

But it breaks down for **amenity-type and access-type indicators** that are coming next (POI density, transit access, healthcare proximity). A rural DeSO in Norrbotten with 900 people and one ICA Nära grocery store is perfectly well-served for its context — but if you rank it nationally against Södermalm with 15 grocery options, it looks terrible. That's not a real disadvantage; it's a measurement artifact from comparing fundamentally different geographic contexts.

The fix is **urbanity-stratified normalization**: rank urban DeSOs against other urban DeSOs, and rural against rural, for indicators where the urban/rural distinction changes what "good" means. This is a standard approach in regional statistics — SCB itself publishes stratified comparisons.

**This task must be completed before the POI system is built**, because POI indicators are the primary consumer of stratified normalization.

## Goals

1. Classify every DeSO by urbanity tier (urban / semi-urban / rural)
2. Add stratified normalization as an option in the indicator system
3. Refactor the NormalizationService to support per-tier ranking
4. Update the admin dashboard to show/configure normalization scope
5. Ensure the scoring engine handles mixed normalization correctly

---

## Step 1: Urbanity Classification

### 1.1 Data Source

SCB publishes **tätort** (urban area / locality) boundaries. A tätort is a contiguous settlement with ≥200 inhabitants and ≤200m between buildings. SCB classifies all of Sweden into tätort vs. non-tätort.

**Two approaches to classify DeSOs:**

**Approach A (recommended): SCB's DeSO metadata**

SCB publishes a classification of DeSOs by density type in their DeSO documentation. Check if the DeSO boundary file or the statistics database includes a built-in urbanity/density field. The DeSO 2025 specification may include a "tätortsgrad" (urbanity degree) column.

Look at:
- The DeSO attribute table in the GeoPackage/Shapefile from SCB geodata
- The DeSO metadata tables on SCB's statistics database
- The RegSO (regional statistical areas) parent classification, which has explicit urban/rural coding

**Approach B: Tätort intersection**

Download tätort boundaries from SCB geodata (`https://geodata.scb.se/geoserver/stat/wfs` — layer `stat:Tatort`). Classify each DeSO based on how much of its population falls within a tätort:

```sql
-- Classify DeSOs by tätort overlap
WITH deso_tatort AS (
    SELECT
        d.deso_code,
        d.population,
        COALESCE(SUM(ST_Area(ST_Intersection(d.geom, t.geom)) / ST_Area(d.geom)), 0) AS tatort_coverage
    FROM deso_areas d
    LEFT JOIN tatort_areas t ON ST_Intersects(d.geom, t.geom)
    GROUP BY d.deso_code, d.population, d.geom
)
SELECT
    deso_code,
    CASE
        WHEN tatort_coverage > 0.8 THEN 'urban'      -- >80% within a tätort
        WHEN tatort_coverage > 0.2 THEN 'semi_urban'  -- 20-80% tätort overlap
        ELSE 'rural'                                    -- <20% tätort coverage
    END AS urbanity_tier
FROM deso_tatort;
```

**Approach C: Population density**

Simplest but least precise. Use the existing `population` and `area_km2` columns:

```sql
UPDATE deso_areas SET urbanity_tier = CASE
    WHEN population / NULLIF(area_km2, 0) > 1500 THEN 'urban'
    WHEN population / NULLIF(area_km2, 0) > 100 THEN 'semi_urban'
    ELSE 'rural'
END;
```

Thresholds need calibration. Urban DeSOs in Stockholm can have 10,000+ people/km². Rural DeSOs in Norrland can be < 5 people/km². The thresholds above should produce roughly:
- Urban: ~2,500 DeSOs
- Semi-urban: ~2,000 DeSOs
- Rural: ~1,600 DeSOs

**Start with Approach C** (can be done immediately with existing data), then upgrade to Approach A or B when you process the tätort boundary data.

### 1.2 Migration

```php
// Add urbanity_tier to deso_areas
Schema::table('deso_areas', function (Blueprint $table) {
    $table->string('urbanity_tier', 20)->nullable()->index()->after('area_km2');
    // 'urban', 'semi_urban', 'rural'
});
```

### 1.3 Classification Command

```bash
php artisan classify:deso-urbanity [--method=density]
```

Options:
- `--method=density` — uses population/area_km2 (Approach C, default)
- `--method=tatort` — uses tätort boundary intersection (Approach B, requires tätort data)
- `--method=scb` — reads from SCB metadata (Approach A, if available)

After classification, log the distribution:

```
Urban:      2,487 DeSOs (40.4%)
Semi-urban: 2,103 DeSOs (34.1%)
Rural:      1,570 DeSOs (25.5%)
```

### 1.4 Verification

Spot-check the classification:
- Stockholm inner city DeSOs → urban ✓
- Suburban DeSOs (Täby, Nacka) → urban or semi-urban ✓
- Small town centers (Falun, Kalmar) → semi-urban ✓
- Remote Norrland DeSOs → rural ✓
- Edge cases: large DeSOs that contain both a small town and surrounding forest → semi-urban (which is correct — the town part provides the amenities)

---

## Step 2: Extend the Indicator System

### 2.1 Add Normalization Scope to Indicators Table

```php
Schema::table('indicators', function (Blueprint $table) {
    $table->string('normalization_scope', 30)->default('national')->after('normalization');
    // 'national' = rank against all DeSOs (default, current behavior)
    // 'urbanity_stratified' = rank within urban/semi-urban/rural tier
});
```

### 2.2 Which Indicators Get Stratified Normalization

| Indicator type | Normalization scope | Rationale |
|---|---|---|
| Income (median, low economic standard) | national | Low income is low income everywhere |
| Employment rate | national | Employment is a universal metric |
| Education level | national | Same |
| School quality (meritvärde) | national | National curriculum, national grading |
| Crime rate | national | Crime is crime |
| Financial distress | national | Debt is debt |
| **Grocery density** | **urbanity_stratified** | What "good access" means differs by context |
| **Restaurant density** | **urbanity_stratified** | Same |
| **Healthcare access** | **urbanity_stratified** | Same |
| **Transit access** | **urbanity_stratified** | Same |
| **Fitness/gym density** | **urbanity_stratified** | Same |
| **Gambling/pawn density** | **urbanity_stratified** | Even negative POIs — 0 gambling venues in a rural area isn't a "strength" |

Rule of thumb: **if the indicator measures physical access to something, stratify. If it measures a rate or outcome, don't.**

### 2.3 Update the Indicator Seeder

When adding new POI/amenity indicators (future task), they should default to `normalization_scope = 'urbanity_stratified'`. Add this to the seeder notes.

---

## Step 3: Refactor NormalizationService

### 3.1 Current Behavior

The service currently does:
1. Fetch all raw_values for an indicator + year
2. Compute PERCENT_RANK across all DeSOs
3. Write normalized_value

### 3.2 New Behavior

```php
public function normalizeIndicator(Indicator $indicator, int $year): void
{
    if ($indicator->normalization_scope === 'urbanity_stratified') {
        $this->normalizeStratified($indicator, $year);
    } else {
        $this->normalizeNational($indicator, $year);
    }
}

private function normalizeNational(Indicator $indicator, int $year): void
{
    // Current implementation — unchanged
    DB::statement("
        UPDATE indicator_values iv
        SET normalized_value = sub.percentile
        FROM (
            SELECT id,
                   PERCENT_RANK() OVER (ORDER BY raw_value) as percentile
            FROM indicator_values
            WHERE indicator_id = ? AND year = ? AND raw_value IS NOT NULL
        ) sub
        WHERE iv.id = sub.id
    ", [$indicator->id, $year]);
}

private function normalizeStratified(Indicator $indicator, int $year): void
{
    // Rank within each urbanity tier separately
    DB::statement("
        UPDATE indicator_values iv
        SET normalized_value = sub.percentile
        FROM (
            SELECT iv2.id,
                   PERCENT_RANK() OVER (
                       PARTITION BY da.urbanity_tier
                       ORDER BY iv2.raw_value
                   ) as percentile
            FROM indicator_values iv2
            JOIN deso_areas da ON da.deso_code = iv2.deso_code
            WHERE iv2.indicator_id = ? AND iv2.year = ? AND iv2.raw_value IS NOT NULL
              AND da.urbanity_tier IS NOT NULL
        ) sub
        WHERE iv.id = sub.id
    ", [$indicator->id, $year]);
}
```

The key difference: `PARTITION BY da.urbanity_tier` in the window function. A rural DeSO with grocery density at the 80th percentile *among rural DeSOs* gets normalized_value = 0.80 — even though its absolute density might be at the 20th percentile nationally.

### 3.3 Edge Cases

**DeSOs without urbanity classification:** If `urbanity_tier IS NULL` (shouldn't happen after classification, but defensively), fall back to national ranking for those DeSOs. Log a warning.

**Very small tier groups:** If a tier has < 50 DeSOs with data for an indicator, percentile ranking becomes unreliable (each rank jump is 2+ percentile points). This shouldn't happen with our tier sizes (~1,500-2,500 each) but add a check: if a tier has fewer than 30 data points, fall back to national ranking for that tier and log a warning.

**The scoring engine doesn't change:** It reads `normalized_value` regardless of how it was computed. Direction application, weighted sum, composite score — all identical. The stratification is invisible downstream of normalization.

---

## Step 4: Admin Dashboard Updates

### 4.1 Indicators Table — New Column

Add a "Scope" column to the admin indicators table:

| Column | Control |
|---|---|
| Normalization Scope | Select: `national` / `urbanity_stratified` |

### 4.2 Visual Indicator

When an indicator uses stratified normalization, show a small info tooltip: "Ranked within urbanity tier (urban / semi-urban / rural) instead of nationally."

### 4.3 Urbanity Distribution Card

On the data quality dashboard (from the governance task), add a card showing the urbanity classification distribution:

```
Urbanity Classification
  Urban:      2,487 DeSOs (40.4%)
  Semi-urban: 2,103 DeSOs (34.1%)
  Rural:      1,570 DeSOs (25.5%)
  Unclassified: 0
```

---

## Step 5: Sidebar Display — Context for Stratified Indicators

### 5.1 The User-Facing Implication

When the sidebar shows an indicator breakdown for a DeSO, stratified indicators should display their context:

```
Grocery Access     ████████░░  82nd among rural areas (1.2 per 1,000)
```

vs. a nationally-normed indicator:

```
Median Income      ██████░░░░  58th percentile (267,000 SEK)
```

The "among rural areas" qualifier helps users understand that a rural DeSO isn't being unfairly compared to Stockholm.

### 5.2 Implementation

The sidebar already gets indicator data from the API. Add `normalization_scope` and `urbanity_tier` to the response:

```php
// In the DeSO detail API response
'indicators' => $indicators->map(fn ($iv) => [
    'slug' => $iv->indicator->slug,
    'name' => $iv->indicator->name,
    'raw_value' => $iv->raw_value,
    'normalized_value' => $iv->normalized_value,
    'unit' => $iv->indicator->unit,
    'direction' => $iv->indicator->direction,
    'normalization_scope' => $iv->indicator->normalization_scope,
    'urbanity_tier' => $desoArea->urbanity_tier,  // The DeSO's own tier
]);
```

The frontend formats the percentile label based on scope:
- `national` → "58th percentile"
- `urbanity_stratified` → "82nd among [urban/semi-urban/rural] areas"

---

## Step 6: Recompute and Verify

### 6.1 Run the Classification

```bash
php artisan classify:deso-urbanity --method=density
```

### 6.2 Recompute Existing Indicators

Even though current indicators all use national scope, run a full recompute to verify nothing breaks:

```bash
php artisan normalize:indicators --year=2024
php artisan compute:scores --year=2024
php artisan check:sentinels --year=2024
```

Scores should be identical to before (since no existing indicator changed scope).

### 6.3 Verification Queries

```sql
-- Check urbanity distribution
SELECT urbanity_tier, COUNT(*) FROM deso_areas GROUP BY urbanity_tier;

-- Verify stratified normalization produces different results than national
-- (This is a test query — run it against a hypothetical stratified indicator)
-- Urban DeSOs should have higher raw_value thresholds for the same percentile
-- compared to rural DeSOs

-- Spot check: Stockholm DeSOs should be 'urban'
SELECT deso_code, deso_name, kommun_name, urbanity_tier, population, area_km2,
       population / NULLIF(area_km2, 0) AS density
FROM deso_areas
WHERE kommun_name LIKE '%Stockholm%'
LIMIT 10;

-- Spot check: Norrland DeSOs should be 'rural'
SELECT deso_code, deso_name, kommun_name, urbanity_tier, population, area_km2,
       population / NULLIF(area_km2, 0) AS density
FROM deso_areas
WHERE lan_name LIKE '%Norrbotten%'
LIMIT 10;
```

### 6.4 Visual Checklist

- [ ] Every DeSO has an urbanity_tier (no NULLs)
- [ ] Distribution is roughly 40/34/26 (urban/semi/rural)
- [ ] Stockholm inner city → urban
- [ ] Small towns → semi_urban
- [ ] Remote areas → rural
- [ ] Existing scores unchanged after recompute
- [ ] Admin indicator table shows normalization scope column
- [ ] Sentinel checks still pass

---

## Notes for the Agent

### This Is a Prerequisite for POIs

The POI system (next task) will add indicators with `normalization_scope = 'urbanity_stratified'`. This task must be complete first so the normalization infrastructure exists.

### Don't Over-Complicate the Tiers

Three tiers is enough. Don't create 5 or 7 tiers. More granularity means smaller groups and less reliable percentile rankings. Three tiers capture the meaningful distinction: city, town, countryside.

### Density Thresholds Need Calibration

The thresholds in Approach C (1500 and 100 people/km²) are starting points. After running the classification, inspect the edge cases. If Danderyd (clearly urban/suburban) lands in semi-urban because it has large parks lowering its density, adjust the thresholds.

Better yet: look at the actual density distribution. There are probably natural break points where urban gives way to semi-urban and semi-urban to rural. Use those rather than arbitrary round numbers.

### The Scoring Engine Is NOT Changing

Stratified normalization happens before scoring. The scoring engine reads `normalized_value` identically regardless of how it was produced. Don't touch the scoring service.

### What NOT to Do

- Don't add a "rural bonus" or "urban penalty" as a multiplier on scores
- Don't change the normalization of existing socioeconomic indicators
- Don't create custom scoring formulas per urbanity tier
- Don't expose the urbanity tier classification as a separate visible feature on the map (it's infrastructure, not a product feature)
- Don't use more than 3 tiers

### Future Enhancement: Tätort Boundaries

Once tätort boundary data is downloaded and stored, switch from density-based classification (Approach C) to proper tätort intersection (Approach B). This will be more accurate for edge cases like DeSOs that contain both a small town and surrounding farmland. But Approach C is a solid start — don't delay this task waiting for tätort data.