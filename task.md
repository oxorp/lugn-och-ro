# TASK: Ingest Historical SCB Data (2019-2023)

## Context

The DeSO crosswalk is built (Phase 1). Now we can ingest 5 years of historical SCB data for all demographic/economic indicators. This gives us the time series depth needed for trend computation (1y, 3y, 5y changes).

**Depends on:** `task-historical-phase1-crosswalk.md` — the crosswalk table must exist.

---

## Step 1: Extend the Ingestion Command

### 1.1 Add Historical Mode to `ingest:scb`

The existing command fetches 2024 data from new DeSO tables. Add a `--historical` flag that uses old tables + crosswalk:

```bash
# Current: fetches 2024 from new tables with DeSO 2025 codes
php artisan ingest:scb --year=2024

# New: fetches historical year from old tables with DeSO 2018 codes, maps through crosswalk
php artisan ingest:scb --year=2022 --historical

# Batch: all years
php artisan ingest:scb-historical --from=2019 --to=2023
```

### 1.2 Table Mapping Config

The research report identified old vs new table names. Add this to the SCB service or config:

```php
// In ScbApiService.php or config/scb_tables.php

private array $historicalTables = [
    'median_income' => [
        'table' => 'HE/HE0110/HE0110A/Tab3InkDesoRegso',
        'contents_code' => '000008AB',
        'years' => [2011, 2012, 2013, 2014, 2015, 2016, 2017, 2018, 2019, 2020, 2021, 2022, 2023],
        'multiply' => 1000,  // SCB returns thousands
        'unit' => 'SEK',
        'notes' => 'Old DeSO codes. Values in thousands — multiply by 1000.',
    ],
    'low_economic_standard_pct' => [
        'table' => 'HE/HE0110/HE0110A/Tab4InkDesoRegso',
        'contents_code' => '000008AC',
        'years' => [2011, 2012, 2013, 2014, 2015, 2016, 2017, 2018, 2019, 2020, 2021, 2022, 2023],
        'unit' => 'percent',
    ],
    'population' => [
        'table' => 'BE/BE0101/BE0101A/FolkmDesoAldKon',
        'contents_code' => '000007Y7',
        'filter' => ['Alder' => 'totalt', 'Kon' => ['1', '2']],  // Sum both sexes, all ages
        'years' => [2010, 2011, 2012, 2013, 2014, 2015, 2016, 2017, 2018, 2019, 2020, 2021, 2022, 2023],
        'unit' => 'number',
        'notes' => 'Need to sum male+female (Kon=1 + Kon=2) for total.',
    ],
    'foreign_background_pct' => [
        'table' => 'BE/BE0101/BE0101Q/FolkmDesoBakgrKon',
        'contents_code' => '000007Y4',
        'filter' => ['Bakgrund' => 'utlandskBakgrund'],  // Foreign background
        'years' => [2010, 2011, 2012, 2013, 2014, 2015, 2016, 2017, 2018, 2019, 2020, 2021, 2022, 2023],
        'unit' => 'percent',
        'notes' => 'Compute as foreign_background_count / total_population.',
    ],
    'education_post_secondary_pct' => [
        'table' => 'UF/UF0506/UF0506B/UtbSUNBefDesoRegso',
        'contents_code' => '000007Z6',
        'years' => [2015, 2016, 2017, 2018, 2019, 2020, 2021, 2022, 2023],
        'unit' => 'percent',
        'notes' => 'OLD table. Ages 25-64 (vs 25-65 in new table). 1-year age range discontinuity at 2024.',
    ],
    'education_below_secondary_pct' => [
        'table' => 'UF/UF0506/UF0506B/UtbSUNBefDesoRegso',
        'contents_code' => '000007Z6',  // Same table, different filter
        'years' => [2015, 2016, 2017, 2018, 2019, 2020, 2021, 2022, 2023],
        'unit' => 'percent',
        'notes' => 'Same table as post_secondary. Different education level filter.',
    ],
    'employment_rate' => [
        // Two tables covering different year ranges
        'table_old' => 'AM/AM0207/AM0207Z/BefDeSoSyssN',  // 2019-2021
        'table_new' => 'AM/AM0210/AM0210G/ArRegDesoStatusN',  // 2020-2024
        'contents_code_old' => '00000569',
        'contents_code_employed_new' => '0000089X',
        'contents_code_total_new' => '0000089Y',
        'years_old' => [2019, 2020, 2021],
        'years_new' => [2020, 2021, 2022, 2023],  // 2024 already ingested from DeSO 2025
        'unit' => 'percent',
        'notes' => 'AM0207 for 2019. AM0210 for 2020-2023 (preferred when both available). Compute rate = employed/total.',
    ],
    'rental_tenure_pct' => [
        'table' => 'BO/BO0104/BO0104T10N',
        'contents_code' => '00000864',
        'years' => [2015, 2016, 2017, 2018, 2019, 2020, 2021, 2022, 2023],
        'unit' => 'percent',
    ],
];
```

### 1.3 Ingestion Flow (Historical)

For each indicator × year:

1. Fetch from SCB using the **old table** and **old DeSO codes** (no `_DeSO2025` suffix)
2. Parse JSON-stat2 response → `[old_deso_code => raw_value]`
3. Map through crosswalk: `CrosswalkService::bulkMapOldToNew(values, unit)` → `[new_deso_code => mapped_value]`
4. Upsert into `indicator_values` with the new DeSO code and correct year
5. Log how many values were fetched, mapped, and stored

```php
public function ingestHistorical(string $indicatorSlug, int $year): void
{
    $config = $this->historicalTables[$indicatorSlug];
    $indicator = Indicator::where('slug', $indicatorSlug)->firstOrFail();

    // 1. Fetch from SCB
    $oldValues = $this->scbService->fetchHistorical($config, $year);
    // Returns: ['0114A0010' => 287000, '0114A0020' => 265000, ...]

    $this->info("Fetched {$count($oldValues)} values for {$indicatorSlug} year {$year} (old DeSO codes)");

    // 2. Map through crosswalk
    $newValues = $this->crosswalkService->bulkMapOldToNew($oldValues, $config['unit']);

    $this->info("Mapped to {$count($newValues)} new DeSO codes");

    // 3. Upsert
    $chunks = collect($newValues)->chunk(500);
    foreach ($chunks as $chunk) {
        foreach ($chunk as $desoCode => $value) {
            IndicatorValue::updateOrCreate(
                ['deso_code' => $desoCode, 'indicator_id' => $indicator->id, 'year' => $year],
                ['raw_value' => $value]
            );
        }
    }

    // 4. Log
    $this->info("Stored {$count($newValues)} indicator_values for {$indicatorSlug} year {$year}");
}
```

---

## Step 2: Batch Historical Command

```bash
php artisan ingest:scb-historical [--from=2019] [--to=2023] [--indicator=median_income]
```

```php
class IngestScbHistorical extends Command
{
    protected $signature = 'ingest:scb-historical
        {--from=2019 : Start year}
        {--to=2023 : End year}
        {--indicator= : Specific indicator slug, or all if omitted}';

    public function handle()
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $slug = $this->option('indicator');

        $indicators = $slug
            ? [$slug]
            : array_keys($this->historicalTables);

        foreach ($indicators as $indicatorSlug) {
            $config = $this->historicalTables[$indicatorSlug];
            $availableYears = $config['years'] ?? [];

            for ($year = $from; $year <= $to; $year++) {
                if (!in_array($year, $availableYears)) {
                    $this->warn("⏭  {$indicatorSlug} year {$year} — not available, skipping");
                    continue;
                }

                $this->info("📥 Ingesting {$indicatorSlug} for {$year}...");
                $this->ingestHistorical($indicatorSlug, $year);
            }
        }

        // Normalize all years after ingestion
        for ($year = $from; $year <= $to; $year++) {
            $this->call('normalize:indicators', ['--year' => $year]);
        }

        $this->info('✅ Historical ingestion complete');
    }
}
```

**Rate limiting:** SCB allows 30 requests per 10 seconds. With 8 indicators × 5 years = 40 requests, add 400ms delay between calls.

---

## Step 3: Employment Rate — Special Handling

Employment uses two different SCB tables with different methodologies:

| Year | Table | Method |
|---|---|---|
| 2019 | AM0207 (BefDeSoSyssN) | Admin-based labour statistics |
| 2020-2023 | AM0210 (ArRegDesoStatusN) | Register-based annual labour market |
| 2024 | AM0210 (ArRegDesoStatusN, DeSO 2025) | Already ingested |

**For 2020-2021 where both tables overlap:** Use AM0210 for consistency with 2022-2024. Only fall back to AM0207 for 2019.

**Employment rate computation from AM0210:**
```
employment_rate = employed (0000089X) / total (0000089Y) × 100
```
Filter: ages 20-64, both sexes, old DeSO codes (for 2020-2023).

**Flag the series break:** The jump from AM0207 (2019) to AM0210 (2020+) may cause a 1-2pp discontinuity. Store a metadata note so trend computation knows about it. Don't smooth it — just document it.

---

## Step 4: Normalize Historical Years

After all historical data is ingested, normalize each year independently:

```bash
for year in 2019 2020 2021 2022 2023; do
    php artisan normalize:indicators --year=$year
done
```

Each year gets its own percentile ranking. This is correct — we want to know "where did this DeSO rank in 2019?" not "where would 2019 values rank against 2024 peers?"

---

## Step 5: Recompute Scores for All Years

```bash
for year in 2019 2020 2021 2022 2023 2024; do
    php artisan compute:scores --year=$year
done
```

Now we have composite scores for 6 years. Trend computation becomes real:
- `trend_1y = score_2024 - score_2023`
- `trend_3y = score_2024 - score_2021`
- `trend_5y = score_2024 - score_2019`

---

## Step 6: Handle Edge Cases

### 6.1 Missing Values

Not every DeSO will have data for every year (especially after crosswalk mapping for split areas). Leave `raw_value` as NULL for missing entries. The normalization and scoring already handle NULLs.

### 6.2 Education Age Range Discontinuity

Old education table: ages 25-64. New table: ages 25-65. This creates a tiny bump at 2024. Add a note to the indicator metadata:

```php
// In indicators table or config
'education_post_secondary_pct' => [
    'series_notes' => 'Age range changed from 25-64 to 25-65 starting 2024. May cause ~0.5pp discontinuity.',
]
```

### 6.3 Income Multiplication

The historical income table returns values in thousands. The current ingestion may or may not multiply. **Verify consistency:**

```sql
-- After historical ingestion, compare 2023 (old table) vs 2024 (new table)
SELECT year, AVG(raw_value), MIN(raw_value), MAX(raw_value)
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
WHERE i.slug = 'median_income'
  AND year IN (2023, 2024)
GROUP BY year;
-- Both years should be in the same order of magnitude (200,000-400,000 SEK range)
-- If 2023 shows 200-400 and 2024 shows 200,000-400,000, the ×1000 multiplier is missing
```

---

## Verification

### Data Completeness

```sql
-- Coverage matrix: indicators × years
SELECT
    i.slug,
    iv.year,
    COUNT(iv.id) as deso_count,
    ROUND(AVG(iv.raw_value)::numeric, 1) as avg_value,
    COUNT(iv.id)::float / 6160 * 100 as coverage_pct
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
WHERE i.source = 'scb'
GROUP BY i.slug, iv.year
ORDER BY i.slug, iv.year;
```

**Expected:**
- 2019-2023: ~5,900-6,100 DeSOs per indicator (some loss from crosswalk edge cases)
- 2024: ~6,100-6,160 DeSOs (already ingested, no crosswalk needed)

### Trend Sanity

```sql
-- Danderyd income should increase over time
SELECT year, raw_value
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
WHERE i.slug = 'median_income'
  AND iv.deso_code IN (SELECT deso_code FROM deso_areas WHERE kommun_name = 'Danderyd')
ORDER BY year;
-- Expect: steady increase from ~350K (2019) to ~430K (2024)

-- National median should also trend upward
SELECT year, PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY raw_value) as national_median
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
WHERE i.slug = 'median_income'
  AND iv.raw_value IS NOT NULL
GROUP BY year
ORDER BY year;
```

### Composite Score Trends

```sql
-- After score recomputation: check that trends make sense
SELECT cs.deso_code, da.kommun_name, cs.year, cs.score
FROM composite_scores cs
JOIN deso_areas da ON da.deso_code = cs.deso_code
WHERE da.kommun_name = 'Danderyd'
  AND cs.deso_code = (SELECT deso_code FROM deso_areas WHERE kommun_name = 'Danderyd' LIMIT 1)
ORDER BY cs.year;
-- Score should be relatively stable (high-scoring area stays high)
```

- [ ] All 8 SCB indicators have data for years 2019-2024 (6 years each)
- [ ] Coverage is >95% for each indicator × year combination
- [ ] Values are in the correct unit/scale (income in SEK not thousands, percentages 0-100 not 0-1)
- [ ] Normalization works per-year (each year has its own 0-1 distribution)
- [ ] Composite scores exist for all 6 years
- [ ] Trends (trend_1y, trend_3y, trend_5y) compute correctly
- [ ] No wild jumps at the 2023→2024 boundary (crosswalk mapping works smoothly)

---

## What NOT to Do

- **DO NOT re-ingest 2024 data.** It's already correct with DeSO 2025 codes. Only ingest 2019-2023.
- **DO NOT normalize across years.** Each year gets its own independent percentile ranking.
- **DO NOT ignore the income multiplication factor.** Verify 2023 values are in the same scale as 2024 before proceeding.
- **DO NOT fetch all years in a single SCB API call.** The 150K cell limit could be exceeded. Fetch one year at a time.