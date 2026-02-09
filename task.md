# TASK: Ingest Historical Data — Skolverket, Kolada, BRÅ, NTU + Data Completeness Dashboard

## Context

Phase 1 (crosswalk) and Phase 2 (SCB historical) give us 6 years of demographic/economic data. This phase adds the remaining sources and builds an admin UI to see completeness across all indicators × years.

**Depends on:** Phase 1 (crosswalk) and Phase 2 (SCB) should be done first, but these can be done in parallel if the crosswalk is ready.

---

## Part A: Skolverket Historical (2020/21 — 2024/25)

### The Finding

The Planned Educations API v3 already returns 5 academic years per school. The current parser only extracts the latest year. Fix the parser, re-ingest, and we get 5 years of school data for free.

### A.1 Fix the Stats Parser

The current `parseGrundskolaStatsResponse()` only grabs the latest `valueType=EXISTS` entry per field. Modify it to return all years:

```php
// In SkolverketApiService.php

/**
 * Parse statistics from Planned Educations API response.
 * Returns: [academic_year => [field => value, ...], ...]
 */
public function parseAllYearsStats(array $response): array
{
    $yearlyStats = [];

    // Navigate to the statistics section of the response
    // The exact path depends on the API response structure — check Swagger
    $statistics = $response['_embedded']['statistics'] ?? $response['statistics'] ?? [];

    foreach ($statistics as $stat) {
        $year = $stat['academicYear'] ?? $stat['timePeriod'] ?? null;
        $field = $stat['typeOfValue'] ?? $stat['name'] ?? null;
        $valueType = $stat['valueType'] ?? null;
        $value = $stat['value'] ?? null;

        if ($year && $field && $valueType === 'EXISTS' && $value !== null) {
            $yearlyStats[$year][$field] = (float) $value;
        }
    }

    return $yearlyStats;
    // Example output:
    // [
    //   '2020/21' => ['certifiedTeachersQuota' => 85.2, 'averageGradesMeritRating9thGrade' => 241.3],
    //   '2021/22' => ['certifiedTeachersQuota' => 87.1, 'averageGradesMeritRating9thGrade' => 238.7],
    //   ...
    // ]
}
```

### A.2 Update the Stats Ingestion Command

```bash
php artisan ingest:skolverket-stats --all-years
```

Modify `ingest:skolverket-stats` to:

1. For each active school, fetch from Planned Educations API v3 (same as current)
2. Parse ALL years from the response (not just the latest)
3. Upsert into `school_statistics` for each academic year found
4. Map academic year to calendar year: `2020/21` → 2021, `2023/24` → 2024

```php
foreach ($allYearsStats as $academicYear => $stats) {
    $calendarYear = (int) substr($academicYear, -2) + 2000; // "2020/21" → 2021

    SchoolStatistic::updateOrCreate(
        ['school_unit_code' => $school->school_unit_code, 'academic_year' => $academicYear],
        [
            'merit_value_17' => $stats['averageGradesMeritRating9thGrade'] ?? null,
            'goal_achievement_pct' => $stats['ratioOfPupilsIn9thGradeWithAllSubjectsPassed'] ?? null,
            'teacher_certification_pct' => $stats['certifiedTeachersQuota'] ?? null,
            'eligibility_pct' => $stats['ratioOfPupils9thGradeEligibleForNationalProgramYR'] ?? null,
            // student_count only available for current year — leave null for historical
        ]
    );
}
```

### A.3 Aggregate Historical School Indicators

After stats are loaded, aggregate to DeSO for each year:

```bash
for year in 2021 2022 2023 2024 2025; do
    php artisan aggregate:school-indicators --calendar-year=$year
done
```

The aggregation command already computes DeSO-level averages. Just pass the year.

### A.4 Skolverket Coverage Notes

- **Teacher certification:** ~88-92% school coverage across all 5 years. Good.
- **Merit value:** ~29-33% coverage. Only schools with year-9 students AND enough students to publish. Low but unavoidable.
- **Goal achievement:** ~27-31%. Same constraint as merit value.
- **Student count:** Current year only. Historical aggregation uses unweighted averages.

---

## Part B: Kolada / Kronofogden Historical (2019-2024)

### B.1 Extend Kolada Ingestion

The current ingestion fetches a single year. Add historical support:

```bash
php artisan ingest:kolada --from=2019 --to=2024
```

The Kolada API supports multi-year queries natively:

```
GET https://api.kolada.se/v2/data/kpi/N00989/year/2019,2020,2021,2022,2023,2024
```

Or fetch one year at a time if the batch endpoint doesn't work well.

### B.2 KPI Mapping

| Kolada KPI | Our Indicator Slug | Notes |
|---|---|---|
| N00989 | debt_rate_pct | % of population with debt at Kronofogden |
| N00990 | median_debt_sek | Median debt amount (SEK) |
| U00958 | eviction_rate | Evictions per 100,000 population |

### B.3 Year Availability

| KPI | 2019 | 2020 | 2021 | 2022 | 2023 | 2024 | 2025 |
|---|---|---|---|---|---|---|---|
| N00989 (debt rate) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| N00990 (median debt) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| U00958 (evictions) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |

### B.4 Notes

- Kolada data is at **kommun level** (290 municipalities). Disaggregation to DeSO uses the existing weighted propensity model.
- No crosswalk needed — kommun codes haven't changed.
- Filter response for 4-digit numeric codes only (exclude grouped `G`-prefix codes and `0000` national total).

---

## Part C: BRÅ Crime Historical (2019-2024)

### C.1 The Challenge

The current BRÅ ingestion uses a CSV download that only has the **current year** (2025 preliminary). Historical data must come from the **statistik.bra.se interactive database**.

### C.2 BRÅ Interactive Database

The BRÅ statistics database at `statistik.bra.se` serves Table 120 (kommun-level reported offences by type and year).

**Method to investigate:** Check if the interactive database has an API or export endpoint. Options:

1. **If API exists:** Use it directly with year parameters
2. **If export only:** Automate the export (select kommun level, all years 2019-2024, download Excel/CSV)
3. **If neither:** Manually download the historical files and place in `storage/app/data/raw/bra/`

```bash
php artisan ingest:bra-historical --from=2019 --to=2024
```

### C.3 Crime Category Mapping

The current ingestion derives three indicators from crime totals + national category proportions:
- `crime_violent_rate` — violent crimes per 1,000 population
- `crime_property_rate` — property crimes per 1,000 population
- `crime_total_rate` — all reported offences per 1,000 population

Historical ingestion should use the same derivation logic. If national category breakdowns are available historically (the Excel file has 10 years), use year-specific proportions.

### C.4 Notes

- BRÅ data is at **kommun level**. Disaggregation to DeSO uses the same model as Kolada.
- 2020 is anomalous (COVID). Document but don't exclude.
- Kommune codes haven't changed, no crosswalk needed.

---

## Part D: NTU Perceived Safety Historical (2019-2024)

### D.1 Already in the Excel File

The research report confirmed that `ntu_lan_2017_2025.xlsx` already contains 9 years (2017-2025) at län level. The current ingestion only loads the latest year.

### D.2 Extend NTU Ingestion

```bash
php artisan ingest:ntu --from=2019 --to=2025
```

Read all sheets/years from the Excel file. The structure should be consistent across years — same columns, same län codes. Sheet R4.1 contains the safety perception data.

### D.3 Notes

- NTU is at **län level** (21 counties) — very coarse. Disaggregated to DeSO via inverse demographic weighting.
- No crosswalk needed — län codes haven't changed.
- All 9 years already available in one file. Quick win.

---

## Part E: Data Completeness Dashboard

### E.1 The Need

With 15+ indicators × 6-7 years = ~100 indicator-year combinations, the admin needs to see at a glance:
- Which indicators have data for which years
- How complete each indicator-year is (% of DeSOs with values)
- Where the gaps are
- When data was last updated

### E.2 API Endpoint

```php
Route::get('/admin/data-completeness', [AdminDataController::class, 'completeness'])
    ->name('admin.data-completeness');
```

```php
public function completeness()
{
    // Build the completeness matrix
    $indicators = Indicator::where('is_active', true)
        ->orderBy('source')
        ->orderBy('category')
        ->orderBy('display_order')
        ->get();

    $years = range(2019, (int) date('Y'));
    $totalDesos = DB::table('deso_areas')->count();

    $matrix = [];
    foreach ($indicators as $indicator) {
        $yearData = DB::table('indicator_values')
            ->where('indicator_id', $indicator->id)
            ->whereIn('year', $years)
            ->groupBy('year')
            ->select(
                'year',
                DB::raw('COUNT(*) as count'),
                DB::raw('COUNT(CASE WHEN raw_value IS NOT NULL THEN 1 END) as non_null_count'),
                DB::raw('ROUND(AVG(raw_value)::numeric, 2) as avg_value'),
                DB::raw('MIN(updated_at) as earliest_update'),
                DB::raw('MAX(updated_at) as latest_update')
            )
            ->get()
            ->keyBy('year');

        $matrix[] = [
            'indicator' => [
                'id' => $indicator->id,
                'slug' => $indicator->slug,
                'name' => $indicator->name,
                'source' => $indicator->source,
                'category' => $indicator->category,
                'unit' => $indicator->unit,
            ],
            'years' => collect($years)->mapWithKeys(function ($year) use ($yearData, $totalDesos) {
                $data = $yearData->get($year);
                return [$year => [
                    'has_data' => $data !== null && $data->non_null_count > 0,
                    'count' => $data->non_null_count ?? 0,
                    'total' => $totalDesos,
                    'coverage_pct' => $data
                        ? round($data->non_null_count / $totalDesos * 100, 1)
                        : 0,
                    'avg_value' => $data->avg_value ?? null,
                    'last_updated' => $data->latest_update ?? null,
                ]];
            }),
        ];
    }

    // Summary stats
    $summary = [
        'total_indicators' => count($indicators),
        'total_years' => count($years),
        'total_cells' => count($indicators) * count($years),
        'filled_cells' => collect($matrix)->sum(fn ($row) =>
            collect($row['years'])->filter(fn ($y) => $y['has_data'])->count()
        ),
        'total_desos' => $totalDesos,
    ];

    return Inertia::render('Admin/DataCompleteness', [
        'matrix' => $matrix,
        'years' => $years,
        'summary' => $summary,
    ]);
}
```

### E.3 Frontend: Completeness Matrix Page

Create `resources/js/Pages/Admin/DataCompleteness.tsx`:

A heatmap-style table where:
- Rows = indicators (grouped by source)
- Columns = years (2019-2025)
- Cells = colored by coverage percentage

```
┌──────────────────────────────┬──────┬──────┬──────┬──────┬──────┬──────┬──────┐
│ Indicator                    │ 2019 │ 2020 │ 2021 │ 2022 │ 2023 │ 2024 │ 2025 │
├──────────────────────────────┼──────┼──────┼──────┼──────┼──────┼──────┼──────┤
│ SCB                          │      │      │      │      │      │      │      │
│  median_income               │ 98%  │ 98%  │ 98%  │ 98%  │ 98%  │ 99%  │  —   │
│  employment_rate             │ 97%  │ 98%  │ 98%  │ 98%  │ 98%  │ 99%  │  —   │
│  education_post_secondary    │ 98%  │ 98%  │ 98%  │ 98%  │ 98%  │ 99%  │  —   │
│  ...                         │      │      │      │      │      │      │      │
├──────────────────────────────┼──────┼──────┼──────┼──────┼──────┼──────┼──────┤
│ Skolverket                   │      │      │      │      │      │      │      │
│  school_merit_value_avg      │  —   │  —   │ 42%  │ 44%  │ 45%  │ 47%  │ 48%  │
│  school_teacher_cert_avg     │  —   │  —   │ 85%  │ 86%  │ 87%  │ 88%  │ 89%  │
│  ...                         │      │      │      │      │      │      │      │
├──────────────────────────────┼──────┼──────┼──────┼──────┼──────┼──────┼──────┤
│ BRÅ                          │      │      │      │      │      │      │      │
│  crime_violent_rate           │ 95%  │ 95%  │ 95%  │ 95%  │ 95%  │ 95%  │ 95%  │
│  perceived_safety            │ 95%  │ 95%  │ 95%  │ 95%  │ 95%  │ 95%  │ 95%  │
│  ...                         │      │      │      │      │      │      │      │
├──────────────────────────────┼──────┼──────┼──────┼──────┼──────┼──────┼──────┤
│ Kolada/Kronofogden            │      │      │      │      │      │      │      │
│  debt_rate_pct               │ 95%  │ 95%  │ 95%  │ 95%  │ 95%  │ 95%  │ 95%  │
│  ...                         │      │      │      │      │      │      │      │
└──────────────────────────────┴──────┴──────┴──────┴──────┴──────┴──────┴──────┘

Summary: 87 of 105 cells filled (83%) — 15 indicators × 7 years
```

### E.4 Cell Styling

| Coverage | Color | Meaning |
|---|---|---|
| 95-100% | Green | Full data |
| 80-94% | Light green | Good, minor gaps |
| 50-79% | Yellow | Partial — investigate |
| 1-49% | Orange | Sparse — expected for some indicators (merit value) |
| 0% / no data | Gray with dash | Not available for this year |

### E.5 Cell Tooltip

On hover, show:
- Exact count: "5,847 of 6,160 DeSOs (94.9%)"
- Average value: "Avg: 287,400 SEK"
- Last updated: "Updated: 2026-02-09"

### E.6 Cell Click

Clicking a cell could show a detail panel or modal with:
- Distribution histogram of values for that indicator × year
- List of DeSOs with missing data (kommun-level summary)
- Comparison to the same indicator's other years (has coverage changed?)

This is nice-to-have. The matrix view is the priority.

### E.7 Navigation

Add to admin sidebar/nav:
```
/admin/indicators       — Indicator weights & settings (existing)
/admin/pipeline          — Ingestion status & controls (existing task)
/admin/data-completeness — NEW: Year × indicator completeness matrix
```

### E.8 Also: Extend the Existing Indicators Admin Page

The existing `/admin/indicators` page shows per-indicator info. Add a "Years" column showing which years have data:

```
| Indicator | Source | Weight | Direction | Years with Data | Coverage (latest) |
|---|---|---|---|---|---|
| median_income | SCB | 0.20 | positive | 2019-2024 (6y) | 99.2% |
| school_merit_value_avg | Skolverket | 0.12 | positive | 2021-2025 (5y) | 47.3% |
| debt_rate_pct | Kolada | 0.05 | negative | 2019-2025 (7y) | 95.1% |
```

The "Years with Data" column is a compact summary: first year — last year (count). Clicking expands to show per-year coverage.

---

## Execution Order

| Step | What | Effort | Notes |
|---|---|---|---|
| A | Skolverket parser fix + re-ingest 5 years | 2-3 hours | Fix parser, re-run ingestion, aggregate |
| B | Kolada historical (2019-2024) | 1-2 hours | Same API, just more years |
| C | BRÅ historical (2019-2024) | 3-4 hours | Need to find/scrape historical source |
| D | NTU historical (2019-2024) | 1 hour | Already in the Excel file |
| E | Data completeness dashboard | 3-4 hours | New admin page + API endpoint |

Total: ~10-14 hours. A, B, and D are quick wins. C depends on BRÅ data access. E is the admin UI work.

**Recommended:** Do A + B + D first (quick, high value), then E (admin visibility), then C (harder but important).

---

## Verification

### After All Historical Ingestion

```sql
-- The big picture: how many indicator-year-deso combinations do we have?
SELECT COUNT(*) as total_rows,
       COUNT(DISTINCT indicator_id) as indicators,
       COUNT(DISTINCT year) as years,
       COUNT(DISTINCT deso_code) as desos
FROM indicator_values
WHERE raw_value IS NOT NULL;

-- Completeness matrix
SELECT
    i.source,
    i.slug,
    iv.year,
    COUNT(iv.id) as deso_count,
    ROUND(COUNT(iv.id)::numeric / 6160 * 100, 1) as coverage_pct
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
WHERE iv.raw_value IS NOT NULL
GROUP BY i.source, i.slug, iv.year
ORDER BY i.source, i.slug, iv.year;
```

### Admin UI Checklist

- [ ] `/admin/data-completeness` page loads and shows the matrix
- [ ] Rows grouped by source (SCB, Skolverket, BRÅ, Kolada)
- [ ] Cells colored by coverage (green/yellow/orange/gray)
- [ ] Tooltips show exact counts and dates
- [ ] SCB indicators show 2019-2024 (6 columns green)
- [ ] Skolverket indicators show 2021-2025 (5 columns, merit value in orange/yellow due to ~30-50% coverage)
- [ ] Kolada indicators show 2019-2024/2025 (6-7 columns green)
- [ ] BRÅ indicators show 2019-2025 (7 columns green)
- [ ] NTU shows 2019-2025 (7 columns green)
- [ ] Summary row shows total filled cells / total possible
- [ ] Existing `/admin/indicators` page shows "Years with Data" column

---

## What NOT to Do

- **DO NOT re-ingest data that's already correct.** 2024 SCB data, current Skolverket data, and current BRÅ/NTU data are already in. Only add the missing historical years.
- **DO NOT block on BRÅ.** If the interactive database is hard to scrape, move on and come back. The other sources are higher priority and easier.
- **DO NOT over-engineer the completeness dashboard.** The matrix view with colored cells is the core deliverable. Drill-down histograms and missing-DeSO lists are nice-to-have.
- **DO NOT normalize all years in one pass.** Each year must be normalized independently — percentile ranks are relative to that year's distribution.