# TASK: Education Data Integrity Audit — Fix Swapped/Wrong SCB Variables

## Context

**Something is seriously wrong with the education indicators.** Two sanity checks failed catastrophically:

- **Södermalm (central Stockholm):** Shows 21.9% post-secondary education, 5th percentile. Should be 55-65%+, 90th+ percentile.
- **Lund (university city):** Shows 14.3% post-secondary education. Lund has one of Sweden's largest universities and should be among the highest in Sweden — 50-60%+.

These aren't edge cases. These are the most obvious "this should be high" areas in the entire country. If they're wrong, the entire education layer is wrong, which means composite scores are wrong, which means the map is lying.

**This is a P0 bug.** The map is currently showing false data to users.

## Likely Root Causes

In order of probability:

### 1. Swapped Variables (Most Likely)
`education_post_secondary_pct` is actually storing the "below secondary" values, and `education_below_secondary_pct` is storing the "post-secondary" values. Lund at 14.3% "post-secondary" would make perfect sense as 14.3% *below secondary* — a well-educated city would have very few people without gymnasium.

**Test:** If Södermalm shows ~21.9% for `education_post_secondary_pct` and something like ~55-65% for `education_below_secondary_pct`, the variables are swapped.

### 2. Wrong SCB ContentsCode
The SCB PX-Web API has dozens of education sub-variables. The ingestion command may have requested the wrong `ContentsCode` — for example, fetching "3+ years post-secondary" when we wanted "any post-secondary", or fetching a sub-category like "post-secondary < 3 years" instead of the total.

### 3. Wrong SCB Table Entirely
The command might be hitting a different table than intended. SCB has multiple education tables at different geographic levels and with different variable definitions.

### 4. DeSO Code Version Mismatch
SCB is transitioning from DeSO 2018 (5,984 areas) to DeSO 2025 (6,160 areas). If the API returns 2018 codes and our database has 2025 codes, values get assigned to wrong areas. This would produce randomly wrong data rather than systematically swapped data.

### 5. JSON-stat2 Parsing Bug
The flat `value` array in JSON-stat2 responses must be mapped back to DeSO codes via the dimension index. If the mapping is off by one, or if multiple dimensions are iterated in the wrong order, every value gets assigned to the wrong DeSO.

---

## Step 1: Diagnose — What Do We Actually Have?

### 1.1 Check the Known-Good Areas

Run these queries to see what our database currently stores for areas where we KNOW the answer:

```sql
-- Areas that MUST have high post-secondary education
-- Lund, Uppsala, Stockholm inner city (Södermalm, Östermalm, Vasastan)
-- Danderyd, Lidingö, Lomma

SELECT
    da.deso_code,
    da.deso_name,
    da.kommun_name,
    post.raw_value as post_secondary_pct,
    post.normalized_value as post_secondary_norm,
    below.raw_value as below_secondary_pct,
    below.normalized_value as below_secondary_norm
FROM deso_areas da
LEFT JOIN indicator_values post ON post.deso_code = da.deso_code
    AND post.indicator_id = (SELECT id FROM indicators WHERE slug = 'education_post_secondary_pct')
LEFT JOIN indicator_values below ON below.deso_code = da.deso_code
    AND below.indicator_id = (SELECT id FROM indicators WHERE slug = 'education_below_secondary_pct')
WHERE da.kommun_name IN ('Lund', 'Uppsala', 'Danderyd', 'Lidingö', 'Lomma')
   OR da.deso_name ILIKE '%söder%'
   OR da.deso_name ILIKE '%östermalm%'
   OR da.deso_name ILIKE '%vasastan%'
ORDER BY da.kommun_name, da.deso_name;
```

**Expected result if variables are swapped:**
- Lund `post_secondary_pct` ≈ 10-20% (should be 50-60%)
- Lund `below_secondary_pct` ≈ 50-60% (should be 10-20%)
- The values are flipped

**Expected result if wrong ContentsCode:**
- Both values might be in a plausible but wrong range
- Or one might be consistently too low/too high

### 1.2 Check the National Distribution

```sql
-- What does our post-secondary data look like nationally?
SELECT
    'post_secondary' as indicator,
    COUNT(*) as deso_count,
    ROUND(MIN(raw_value)::numeric, 1) as min_val,
    ROUND(PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY raw_value)::numeric, 1) as p25,
    ROUND(PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY raw_value)::numeric, 1) as median,
    ROUND(PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY raw_value)::numeric, 1) as p75,
    ROUND(MAX(raw_value)::numeric, 1) as max_val,
    ROUND(AVG(raw_value)::numeric, 1) as mean
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
WHERE i.slug = 'education_post_secondary_pct'
  AND iv.raw_value IS NOT NULL

UNION ALL

SELECT
    'below_secondary' as indicator,
    COUNT(*),
    ROUND(MIN(raw_value)::numeric, 1),
    ROUND(PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY raw_value)::numeric, 1),
    ROUND(PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY raw_value)::numeric, 1),
    ROUND(PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY raw_value)::numeric, 1),
    ROUND(MAX(raw_value)::numeric, 1),
    ROUND(AVG(raw_value)::numeric, 1)
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
WHERE i.slug = 'education_below_secondary_pct'
  AND iv.raw_value IS NOT NULL;
```

**What we should see (from SCB national statistics 2024):**
- Post-secondary education (ages 25-64): national average ~29-35% depending on exact definition
  - Distribution: ranges from ~10% (some rural areas) to ~70% (university city cores)
  - Median should be around 25-30%
- Below secondary education (ages 25-64): national average ~10-15%
  - Distribution: ranges from ~3% to ~40%+
  - Median should be around 10-12%

**If the numbers look right nationally but wrong locally**, it's a DeSO mapping issue. If the **national distribution itself looks wrong**, it's a variable/table issue.

### 1.3 Check the SCB API Call

Look at the ingestion command code to find:

```bash
# Find the SCB API call for education data
grep -rn "education\|UF0506\|utbildning\|ContentsCode" app/Console/Commands/ app/Services/ScbApiService.php
```

Identify:
1. Which SCB table is being queried (table ID / API path)
2. Which `ContentsCode` values are being requested
3. Which `Region` filter is being used (DeSO level?)
4. How the response values are mapped to indicator slugs

### 1.4 Cross-Reference with SCB Directly

Go to https://www.statistikdatabasen.scb.se and manually look up the education data for a Lund DeSO code. Compare the value SCB shows vs what our database has. This tells us definitively whether the problem is in the API call or in the parsing/storage.

```sql
-- Get some Lund DeSO codes to look up manually on SCB
SELECT deso_code, deso_name FROM deso_areas WHERE kommun_name = 'Lund' LIMIT 5;
```

---

## Step 2: Fix Based on Diagnosis

### Fix A: If Variables Are Swapped

The `education_post_secondary_pct` and `education_below_secondary_pct` columns have each other's data.

**Database fix (swap the values):**

```sql
-- CAREFUL: Run in a transaction, verify before committing

BEGIN;

-- Step 1: Get the indicator IDs
SELECT id, slug FROM indicators WHERE slug IN ('education_post_secondary_pct', 'education_below_secondary_pct');
-- Let's say post_secondary has id=X and below_secondary has id=Y

-- Step 2: Swap the indicator_id references
-- Temporarily use a third ID to avoid unique constraint violations
UPDATE indicator_values SET indicator_id = -1 WHERE indicator_id = X;  -- post → temp
UPDATE indicator_values SET indicator_id = X WHERE indicator_id = Y;   -- below → post
UPDATE indicator_values SET indicator_id = Y WHERE indicator_id = -1;  -- temp → below

-- Step 3: Verify the swap worked
SELECT da.kommun_name, iv.raw_value
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
JOIN deso_areas da ON da.deso_code = iv.deso_code
WHERE i.slug = 'education_post_secondary_pct'
  AND da.kommun_name = 'Lund'
LIMIT 5;
-- Should now show 50-60% for Lund

COMMIT;  -- Only if verification passes!
```

**Then fix the ingestion command** so this doesn't happen on the next data refresh. Swap the ContentsCode mapping in the SCB API call.

### Fix B: If Wrong ContentsCode

Find the correct ContentsCode by exploring the SCB API:

```bash
# List available contents codes for the education DeSO table
curl -s "https://api.scb.se/OV0104/v1/doris/en/ssd/UF/UF0506/UF0506B/TabVXUtbniva" | python3 -m json.tool | grep -A5 "ContentsCode"
```

The education level variable we want is typically:
- **"Eftergymnasial utbildning, 3 år eller mer"** — post-secondary 3+ years
- Or the broader **"Eftergymnasial utbildning"** — any post-secondary

We might accidentally be fetching:
- "Förgymnasial utbildning" (pre-gymnasium = below secondary)
- A sub-category that's too narrow
- A count instead of a percentage

Update the `ContentsCode` in the SCB API service and re-ingest.

### Fix C: If DeSO Code Mismatch

If SCB returns DeSO 2018 codes and our database has DeSO 2025:

1. Check how many DeSO codes from the SCB response match our database:
```sql
-- After ingestion, how many matched vs didn't?
SELECT
    COUNT(*) FILTER (WHERE da.deso_code IS NOT NULL) as matched,
    COUNT(*) FILTER (WHERE da.deso_code IS NULL) as unmatched
FROM indicator_values iv
LEFT JOIN deso_areas da ON da.deso_code = iv.deso_code
WHERE iv.indicator_id = (SELECT id FROM indicators WHERE slug = 'education_post_secondary_pct');
```

2. If there are many unmatched, we need a DeSO 2018→2025 crosswalk table from SCB.

### Fix D: If JSON-stat2 Parsing Bug

Check `ScbApiService::parseJsonStat2()` for:
- Correct dimension ordering (Region × ContentsCode × Time)
- Correct index mapping (the `value` array is flat, mapped by cartesian product of dimensions)
- Off-by-one errors in the index calculation

Add a sanity check to the parser:
```php
// After parsing, spot-check a known value
$lundDeso = '1281A0010'; // or whatever Lund's first DeSO code is
$parsedValue = $result[$lundDeso] ?? null;
Log::info("Sanity check: Lund DeSO {$lundDeso} education value = {$parsedValue}");
// This should be 50-60% for post-secondary, 10-15% for below-secondary
```

---

## Step 3: Re-ingest and Renormalize

After fixing the root cause:

```bash
# 1. Re-ingest education data with corrected API call
php artisan ingest:scb --indicator=education_post_secondary_pct
php artisan ingest:scb --indicator=education_below_secondary_pct

# 2. Renormalize (percentile ranks change when values change)
php artisan normalize:indicators --year=2024

# 3. Recompute composite scores
php artisan compute:scores --year=2024
```

---

## Step 4: Verify the Fix

### 4.1 Sanity Check — Known Areas

```sql
-- After fix: these should all make sense now
SELECT
    da.kommun_name,
    da.deso_name,
    ROUND(post.raw_value::numeric, 1) as post_secondary_pct,
    ROUND(post.normalized_value::numeric * 100) as post_secondary_percentile,
    ROUND(below.raw_value::numeric, 1) as below_secondary_pct,
    ROUND(below.normalized_value::numeric * 100) as below_secondary_percentile
FROM deso_areas da
LEFT JOIN indicator_values post ON post.deso_code = da.deso_code
    AND post.indicator_id = (SELECT id FROM indicators WHERE slug = 'education_post_secondary_pct')
LEFT JOIN indicator_values below ON below.deso_code = da.deso_code
    AND below.indicator_id = (SELECT id FROM indicators WHERE slug = 'education_below_secondary_pct')
WHERE da.kommun_name IN ('Lund', 'Danderyd', 'Lidingö')
ORDER BY post.raw_value DESC
LIMIT 10;
```

**Expected after fix:**
| kommun | post_secondary_pct | post_secondary_percentile | below_secondary_pct | below_secondary_percentile |
|---|---|---|---|---|
| Lund (central) | 55-65% | 95-99th | 5-10% | 5-15th |
| Danderyd | 60-70% | 97-99th | 3-8% | 1-10th |
| Lidingö | 55-65% | 95-99th | 5-10% | 5-15th |

### 4.2 Sanity Check — Known Low-Education Areas

```sql
-- Areas that should have LOW post-secondary education
-- Typical: smaller industrial towns, some immigrant-dense suburbs
SELECT
    da.kommun_name,
    da.deso_name,
    ROUND(post.raw_value::numeric, 1) as post_secondary_pct
FROM deso_areas da
JOIN indicator_values post ON post.deso_code = da.deso_code
    AND post.indicator_id = (SELECT id FROM indicators WHERE slug = 'education_post_secondary_pct')
WHERE da.kommun_name IN ('Filipstad', 'Gislaved', 'Lessebo')
ORDER BY post.raw_value ASC
LIMIT 10;
```

**Expected:** 10-20% post-secondary. If these now show 50-60%, we over-corrected (swapped the wrong way).

### 4.3 Visual Check

- [ ] Open the map, click Södermalm → post-secondary education should show 55-65%, high percentile, green bar
- [ ] Click a Lund DeSO → similar, high post-secondary
- [ ] Click a known low-education area → low post-secondary, red bar
- [ ] The overall map color pattern should shift (education indicators contribute to composite score)
- [ ] Danderyd and Lidingö should still be green overall
- [ ] The education indicator tooltip shows a value that matches the national average context (~29%)

---

## Step 5: Add Permanent Sanity Checks

### 5.1 Post-Ingestion Validation

Add automatic sanity checks to the ingestion command that run after every data load:

```php
// In IngestScbData.php, after data is stored

private function validateEducationData(): void
{
    // Known reference points (won't change dramatically year to year)
    $checks = [
        // [deso_code_pattern, indicator_slug, expected_min, expected_max, description]
        ['kommun_name' => 'Lund', 'slug' => 'education_post_secondary_pct', 'min' => 40, 'max' => 80, 'label' => 'Lund post-secondary'],
        ['kommun_name' => 'Danderyd', 'slug' => 'education_post_secondary_pct', 'min' => 50, 'max' => 80, 'label' => 'Danderyd post-secondary'],
        ['kommun_name' => 'Lund', 'slug' => 'education_below_secondary_pct', 'min' => 2, 'max' => 20, 'label' => 'Lund below-secondary'],
    ];

    foreach ($checks as $check) {
        $avgValue = DB::table('indicator_values')
            ->join('indicators', 'indicators.id', '=', 'indicator_values.indicator_id')
            ->join('deso_areas', 'deso_areas.deso_code', '=', 'indicator_values.deso_code')
            ->where('indicators.slug', $check['slug'])
            ->where('deso_areas.kommun_name', $check['kommun_name'])
            ->avg('indicator_values.raw_value');

        if ($avgValue < $check['min'] || $avgValue > $check['max']) {
            Log::error("SANITY CHECK FAILED: {$check['label']} avg={$avgValue}, expected {$check['min']}-{$check['max']}");
            $this->error("⚠️  SANITY CHECK FAILED: {$check['label']} = {$avgValue}% (expected {$check['min']}-{$check['max']}%)");
        } else {
            $this->info("✓ {$check['label']} = " . round($avgValue, 1) . "% — OK");
        }
    }
}
```

### 5.2 Generalize to All Indicators

Create a validation config that can be extended for every indicator:

```php
// config/data_sanity_checks.php

return [
    'education_post_secondary_pct' => [
        ['kommun' => 'Lund', 'min' => 40, 'max' => 80],
        ['kommun' => 'Danderyd', 'min' => 50, 'max' => 80],
        ['national_median_min' => 20, 'national_median_max' => 40],
    ],
    'education_below_secondary_pct' => [
        ['kommun' => 'Lund', 'min' => 2, 'max' => 20],
        ['kommun' => 'Danderyd', 'min' => 1, 'max' => 15],
        ['national_median_min' => 5, 'national_median_max' => 20],
    ],
    'median_income' => [
        ['kommun' => 'Danderyd', 'min' => 350000, 'max' => 600000],
        ['kommun' => 'Filipstad', 'min' => 150000, 'max' => 250000],
        ['national_median_min' => 200000, 'national_median_max' => 300000],
    ],
    'employment_rate' => [
        ['national_median_min' => 60, 'national_median_max' => 85],
    ],
    // Add checks for every indicator as they're ingested
];
```

### 5.3 Artisan Command

```bash
php artisan validate:indicators [--year=2024] [--indicator=education_post_secondary_pct]
```

Runs all sanity checks and reports pass/fail. Should be called automatically at the end of every ingestion run. If any check fails, log a warning but don't block — the admin should review.

---

## Step 6: Audit All Other Indicators

While we're here, spot-check EVERY indicator, not just education. The same type of bug could affect income, employment, or anything else.

```sql
-- Quick sanity: for each indicator, show the top 5 and bottom 5 kommuner by average value
-- If Danderyd shows up at the bottom of median_income, we have the same problem

SELECT i.slug, da.kommun_name,
    ROUND(AVG(iv.raw_value)::numeric, 1) as avg_value,
    RANK() OVER (PARTITION BY i.slug ORDER BY AVG(iv.raw_value) DESC) as rank_desc
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
JOIN deso_areas da ON da.deso_code = iv.deso_code
WHERE i.is_active = true
  AND iv.raw_value IS NOT NULL
GROUP BY i.slug, da.kommun_name
HAVING COUNT(*) >= 3  -- Only kommuner with enough DeSOs
ORDER BY i.slug, avg_value DESC;
```

For each indicator, check:
- [ ] `median_income`: Danderyd, Lidingö, Täby at the top? Filipstad, Lessebo near the bottom?
- [ ] `employment_rate`: University cities and wealthy suburbs high? Areas with known unemployment low?
- [ ] `low_economic_standard_pct`: Inverse of median_income pattern?
- [ ] `education_post_secondary_pct`: University cities at top? ← **THIS IS THE ONE THAT'S BROKEN**
- [ ] `education_below_secondary_pct`: Should be inverse of post-secondary pattern

---

## Priority

This is **urgent**. Every user looking at the map right now sees wrong education data, which feeds into wrong composite scores, which means the entire map coloring is partially wrong. Education indicators carry significant weight (0.10 + 0.05 = 0.15 of the composite score for SCB education alone, plus 0.25 for school quality which may be correct since it's from a different source).

Fix order:
1. **Diagnose** (Step 1) — 15 minutes
2. **Fix** (Step 2) — depends on root cause, 15-60 minutes
3. **Re-ingest + renormalize** (Step 3) — 5-10 minutes
4. **Verify** (Step 4) — 10 minutes
5. **Add sanity checks** (Step 5) — 30 minutes
6. **Audit other indicators** (Step 6) — 30 minutes

Total: 2-3 hours if the fix is straightforward (swapped variables).

---

## What NOT to Do

- **DO NOT just swap the values in the database without finding the root cause.** If the ingestion command is wrong, the next data refresh will re-break it.
- **DO NOT assume only education is affected.** Check every indicator.
- **DO NOT skip the sanity checks in Step 5.** This bug shipped to production unnoticed. Automated validation prevents it from happening again.
- **DO NOT trust the map until this is fixed.** The composite scores are partially computed from wrong education data.