# TASK: Trend Trajectories — Historical Data & Per-Indicator Trends

## Context

The platform shows a snapshot: "this DeSO scores 72 right now." That's useful but incomplete. What users actually want to know is: "is this area getting better or worse?" A score of 72 that was 60 three years ago tells a completely different story than a 72 that was 85.

This task adds the time dimension. It fetches historical data (3 years back), computes per-indicator trends, and displays them honestly in the UI. Honestly means: if we only have trend data for 3 of 8 indicators, we show trends for those 3 and say nothing about the rest. We never extrapolate. We never fabricate a composite trend arrow from incomplete data.

**There is one major structural problem:** SCB published a new DeSO version in 2025 (6,160 areas) that replaced the 2018 version (5,984 areas). Historical statistics use old codes; new statistics use new codes. About 500–1,000 DeSOs were affected (split, merged, redrawn, or recoded). For affected areas, year-over-year comparison is meaningless — you'd be comparing different geographies. Per your decision: **we exclude affected DeSOs from trend calculations entirely** and show "Trend data not available for this area" instead.

## Goals

1. Fetch 3 years of historical data for all existing SCB indicators
2. Fetch historical school statistics (Skolverket) where available
3. Build a DeSO 2018→2025 correspondence table to identify changed areas
4. Compute per-indicator trends for each DeSO (only where boundary is unchanged)
5. Display individual indicator trends in the sidebar (arrows, percentages)
6. No composite trend arrow on the map — individual indicator trends only for now
7. Handle missing data honestly at every level

---

## Step 1: DeSO Boundary Change Detection

### 1.1 The Problem

SCB published DeSO 2025 in January 2025. Changes include:
- **Splits:** One DeSO 2018 became two or more DeSO 2025 areas (densely populated areas that grew past 2,700 people)
- **Merges:** Two DeSO 2018 areas became one DeSO 2025 (depopulated mining areas in Norrbotten)
- **Cosmetic changes:** Boundary adjusted to follow roads/water better, but same area conceptually
- **Code changes:** Same geographic area but code changed (e.g., category changed from C to B)
- **Unchanged:** Same code, same boundary — the majority (~5,000+)

### 1.2 Download the Correspondence Data

SCB publishes "Historiska förändringar i DeSO" as an Excel file on the DeSO geodata page:
`https://www.scb.se/vara-tjanster/oppna-data/oppna-geodata/oppna-geodata-for-deso---demografiska-statistikomraden/`

Download this file. It should contain a mapping between DeSO 2018 codes and DeSO 2025 codes, with a change type indicator.

### 1.3 Boundary Changes Table

```php
Schema::create('deso_boundary_changes', function (Blueprint $table) {
    $table->id();
    $table->string('deso_2018_code', 10)->index();
    $table->string('deso_2025_code', 10)->index();
    $table->string('change_type', 30);  // 'unchanged', 'split', 'merged', 'cosmetic', 'recoded', 'new'
    $table->text('notes')->nullable();
    $table->timestamps();
});
```

### 1.4 Import Command

```bash
php artisan import:deso-changes
```

Parses the SCB Excel file and populates `deso_boundary_changes`. If the Excel format isn't machine-friendly, the agent may need to interpret the data manually or cross-reference DeSO 2018 and DeSO 2025 code lists to detect:
- Codes that exist in 2025 but not in 2018 → **new**
- Codes that exist in 2018 but not in 2025 → **retired** (split, merged, or recoded)
- Codes that exist in both → **unchanged** or **cosmetic**

**Alternative approach if the Excel is unhelpful:** Download both DeSO 2018 and DeSO 2025 boundary files from SCB geodata. Compare code lists programmatically. For codes that don't match, use spatial intersection to determine whether it's a split, merge, or recode.

### 1.5 Mark DeSOs as Trend-Eligible

Add a column to `deso_areas`:

```php
$table->boolean('trend_eligible')->default(true);
```

Set `trend_eligible = false` for any DeSO 2025 code where:
- The DeSO didn't exist in 2018 (`change_type = 'new'`)
- The DeSO was created by splitting an old one (`change_type = 'split'`)
- The DeSO was created by merging old ones (`change_type = 'merged'`)

Set `trend_eligible = true` for:
- `unchanged`: Same code, same boundary
- `cosmetic`: Minor boundary tweaks that don't materially affect the population or statistics
- `recoded`: Same area, different code — we can map old→new

**Expected result:** ~5,000–5,500 DeSOs are trend-eligible. ~600–1,200 are not.

### 1.6 Code Mapping Table (for Recoded DeSOs)

For DeSOs that were simply recoded (same geography, new code), we need a lookup to join historical data:

```php
Schema::create('deso_code_mappings', function (Blueprint $table) {
    $table->id();
    $table->string('old_code', 10)->index();     // DeSO 2018 code
    $table->string('new_code', 10)->index();     // DeSO 2025 code
    $table->string('mapping_type', 20);          // 'identical', 'recoded', 'cosmetic'
    $table->timestamps();
});
```

For `identical` DeSOs, old_code = new_code. For `recoded`, they differ. When fetching historical data, use this mapping to link old codes to current codes.

---

## Step 2: Historical Data Ingestion

### 2.1 Extend the SCB Ingestion Command

The current `ingest:scb` command fetches one year. Extend it to fetch multiple years:

```bash
php artisan ingest:scb --year=2022
php artisan ingest:scb --year=2023
php artisan ingest:scb --year=2024
```

Or with a range:

```bash
php artisan ingest:scb --from=2022 --to=2024
```

### 2.2 Historical Data is on DeSO 2018 Codes

**Critical:** SCB explicitly states that historical tables remain on DeSO 2018 codes. Statistics published in 2025 use DeSO 2025 codes. This means:

- Data for 2022, 2023: DeSO 2018 codes in the API response
- Data for 2024 (published in 2025): DeSO 2025 codes in the API response

The ingestion command must:
1. Detect which code version the API response uses (check if codes match our deso_areas table)
2. If historical (2018 codes): translate to 2025 codes using `deso_code_mappings`
3. If codes can't be mapped (split/merged areas): skip and log
4. Store in `indicator_values` using the DeSO 2025 code (our canonical code)

```php
// In IngestScbData, after parsing API response:
foreach ($desoValues as $responseCode => $value) {
    // Try direct match first (DeSO 2025 code or identical code)
    $canonicalCode = $responseCode;

    if (!DesoArea::where('deso_code', $responseCode)->exists()) {
        // Try the mapping table
        $mapping = DesoCodeMapping::where('old_code', $responseCode)->first();
        if ($mapping) {
            $canonicalCode = $mapping->new_code;
        } else {
            // Code doesn't exist in 2025 and has no mapping → skip
            $this->unmappedCodes++;
            continue;
        }
    }

    IndicatorValue::updateOrCreate(
        ['deso_code' => $canonicalCode, 'indicator_id' => $indicator->id, 'year' => $year],
        ['raw_value' => $value]
    );
}
```

### 2.3 Available Historical Depth per Indicator

Check what years are available for each SCB table. The agent should query the API to discover available time periods:

```
POST https://api.scb.se/OV0104/v1/doris/en/ssd/{table_path}
```

With an empty query body, the API returns metadata including available time periods.

Expected availability (approximate):

| Indicator | Available years (DeSO level) | Notes |
|---|---|---|
| median_income | 2011–2023 | Excellent depth |
| low_economic_standard_pct | 2011–2023 | Excellent depth |
| employment_rate | 2018–2023 | Starts with DeSO creation |
| education_post_secondary_pct | 2018–2023 | Starts with DeSO creation |
| education_below_secondary_pct | 2018–2023 | Same |
| foreign_background_pct | 2010–2024 | Longest series |
| population | 2010–2024 | Longest series |
| rental_tenure_pct | 2013–2023 | Good depth |

For our 3-year window, we need 2022–2024. Most indicators should have this.

### 2.4 Historical School Data

Skolverket's school statistics are annual. The `ingest:skolverket-stats` command currently fetches the latest year. Extend to fetch historical:

```bash
php artisan ingest:skolverket-stats --academic-year=2021/22
php artisan ingest:skolverket-stats --academic-year=2022/23
php artisan ingest:skolverket-stats --academic-year=2023/24
```

The Planned Educations API may have historical data, or the agent may need to download historical Excel files from Skolverket's statistics page.

After historical stats are loaded, re-run:
```bash
php artisan aggregate:school-indicators --academic-year=2022/23
php artisan aggregate:school-indicators --academic-year=2023/24
```

### 2.5 POI Data: No History

POIs (OSM, Google Places) have no historical snapshots. They represent current state only. **No trend is computed for POI-based indicators.** This is expected and correct. From this point forward, each monthly POI scrape is stored with a timestamp. In 12+ months, we'll have enough snapshots to compute a POI trend. But not at launch.

---

## Step 3: Trend Computation

### 3.1 Indicator Trend Table

```php
Schema::create('indicator_trends', function (Blueprint $table) {
    $table->id();
    $table->string('deso_code', 10)->index();
    $table->foreignId('indicator_id')->constrained()->index();
    $table->integer('base_year');                           // e.g., 2022
    $table->integer('end_year');                            // e.g., 2024
    $table->integer('data_points');                         // How many years of data we have
    $table->decimal('absolute_change', 14, 4)->nullable();  // end_value - base_value
    $table->decimal('percent_change', 8, 2)->nullable();    // ((end - base) / base) * 100
    $table->string('direction', 10);                        // 'rising', 'falling', 'stable', 'insufficient'
    $table->decimal('confidence', 3, 2)->nullable();        // 0.00-1.00 based on data completeness
    $table->timestamps();

    $table->unique(['deso_code', 'indicator_id', 'base_year', 'end_year']);
});
```

### 3.2 Trend Computation Service

Create `app/Services/TrendService.php`:

```php
class TrendService
{
    // Thresholds for "stable" vs "rising" vs "falling"
    private const STABLE_THRESHOLD_PCT = 3.0;  // ±3% = stable

    public function computeTrends(int $baseYear, int $endYear): void
    {
        $indicators = Indicator::where('is_active', true)
            ->where('direction', '!=', 'neutral')  // Don't compute trends for neutral indicators
            ->get();

        foreach ($indicators as $indicator) {
            $this->computeIndicatorTrend($indicator, $baseYear, $endYear);
        }
    }

    private function computeIndicatorTrend(Indicator $indicator, int $baseYear, int $endYear): void
    {
        // Only for trend-eligible DeSOs
        $results = DB::select("
            SELECT
                base.deso_code,
                base.raw_value AS base_value,
                latest.raw_value AS end_value,
                (SELECT COUNT(*) FROM indicator_values iv
                 WHERE iv.deso_code = base.deso_code
                 AND iv.indicator_id = ?
                 AND iv.year BETWEEN ? AND ?
                 AND iv.raw_value IS NOT NULL) AS data_points
            FROM indicator_values base
            JOIN indicator_values latest
                ON latest.deso_code = base.deso_code
                AND latest.indicator_id = base.indicator_id
                AND latest.year = ?
            JOIN deso_areas da ON da.deso_code = base.deso_code
            WHERE base.indicator_id = ?
              AND base.year = ?
              AND base.raw_value IS NOT NULL
              AND latest.raw_value IS NOT NULL
              AND da.trend_eligible = true
        ", [$indicator->id, $baseYear, $endYear, $endYear, $indicator->id, $baseYear]);

        foreach ($results as $row) {
            $absoluteChange = $row->end_value - $row->base_value;
            $percentChange = $row->base_value != 0
                ? ($absoluteChange / abs($row->base_value)) * 100
                : null;

            $direction = $this->classifyDirection($percentChange, $row->data_points);
            $confidence = $this->computeConfidence($row->data_points, $baseYear, $endYear);

            IndicatorTrend::updateOrCreate(
                [
                    'deso_code' => $row->deso_code,
                    'indicator_id' => $indicator->id,
                    'base_year' => $baseYear,
                    'end_year' => $endYear,
                ],
                [
                    'data_points' => $row->data_points,
                    'absolute_change' => $absoluteChange,
                    'percent_change' => $percentChange,
                    'direction' => $direction,
                    'confidence' => $confidence,
                ]
            );
        }
    }

    private function classifyDirection(?float $percentChange, int $dataPoints): string
    {
        if ($dataPoints < 2 || $percentChange === null) {
            return 'insufficient';
        }
        if (abs($percentChange) <= self::STABLE_THRESHOLD_PCT) {
            return 'stable';
        }
        return $percentChange > 0 ? 'rising' : 'falling';
    }

    private function computeConfidence(int $dataPoints, int $baseYear, int $endYear): float
    {
        $expectedPoints = $endYear - $baseYear + 1;
        // Confidence based on data completeness
        return min(1.0, $dataPoints / $expectedPoints);
    }
}
```

### 3.3 Direction Interpretation

The `direction` field here is about the raw value movement, not about whether it's good or bad. Interpretation depends on the indicator's `direction` property:

| Indicator direction | Raw trend | Interpretation for user |
|---|---|---|
| positive (income) | rising | ↑ Improving |
| positive (income) | falling | ↓ Declining |
| negative (crime) | rising | ↓ Worsening |
| negative (crime) | falling | ↑ Improving |
| positive (employment) | stable | → Stable |

The UI applies this logic when rendering — the stored trend is just the raw direction.

### 3.4 Artisan Command

```bash
php artisan compute:trends [--base-year=2022] [--end-year=2024]
```

---

## Step 4: What About Composite Trends?

### 4.1 The Decision: Not Yet

Per your direction: **no composite trend arrow on the map for now.** The composite_scores table already has `trend_1y` and `trend_3y` columns from the original design, but we leave them NULL.

**Why this is the right call:**
- Different indicators have different historical depth (income: 5+ years, POIs: 0 years)
- Averaging a trend from 4 indicators that have data with 4 that don't is misleading
- A "↑ Rising" badge on the map that's based on income alone (because that's all we have trend data for) would be deceptive
- Better to show per-indicator arrows in the sidebar and let users form their own judgment

### 4.2 When to Add Composite Trends

Once **at least 60% of the active weight budget** has trend data available (meaning indicators covering ≥ 0.60 of the total weight sum have 3-year history), we can consider a composite trend. This is probably ~6 months out, once BRÅ and Kronofogden are integrated.

Add this threshold as a config value so it can be adjusted:

```php
// config/scoring.php
return [
    'composite_trend_min_weight_coverage' => 0.60,
];
```

### 4.3 Future Implementation Note

When composite trends are eventually computed:
```
composite_trend = Σ(weight_i × indicator_trend_direction_i × percent_change_i) / Σ(weight_i for indicators with trend data)
```

Only indicators with `direction != 'insufficient'` contribute. The confidence of the composite trend = sum of contributing weights / sum of all active weights.

---

## Step 5: API Changes

### 5.1 Extend DeSO Detail Response

When the sidebar loads data for a DeSO, include trend information:

```php
// In the DeSO detail / indicator breakdown response
public function show(string $desoCode)
{
    $deso = DesoArea::where('deso_code', $desoCode)->firstOrFail();

    $indicators = IndicatorValue::where('deso_code', $desoCode)
        ->where('year', $this->currentYear)
        ->with('indicator')
        ->get()
        ->map(function ($iv) use ($desoCode) {
            $trend = IndicatorTrend::where('deso_code', $desoCode)
                ->where('indicator_id', $iv->indicator_id)
                ->latest('end_year')
                ->first();

            return [
                'slug' => $iv->indicator->slug,
                'name' => $iv->indicator->name,
                'raw_value' => $iv->raw_value,
                'normalized_value' => $iv->normalized_value,
                'unit' => $iv->indicator->unit,
                'direction' => $iv->indicator->direction,
                'normalization_scope' => $iv->indicator->normalization_scope,
                'trend' => $trend ? [
                    'direction' => $trend->direction,       // 'rising', 'falling', 'stable', 'insufficient'
                    'percent_change' => $trend->percent_change,
                    'absolute_change' => $trend->absolute_change,
                    'base_year' => $trend->base_year,
                    'end_year' => $trend->end_year,
                    'data_points' => $trend->data_points,
                    'confidence' => $trend->confidence,
                ] : null,
            ];
        });

    return response()->json([
        'deso' => [...],
        'score' => [...],
        'indicators' => $indicators,
        'trend_eligible' => $deso->trend_eligible,
    ]);
}
```

### 5.2 New: Trend Metadata

The sidebar should tell users about trend data availability:

```php
// Also return in the DeSO response:
'trend_meta' => [
    'eligible' => $deso->trend_eligible,
    'reason' => $deso->trend_eligible ? null : 'Area boundaries changed in 2025 revision',
    'indicators_with_trends' => $indicatorsWithTrendCount,
    'indicators_total' => $indicatorsTotalCount,
    'period' => $deso->trend_eligible ? '2022–2024' : null,
],
```

---

## Step 6: UI — Sidebar Trend Display

### 6.1 Indicator Bars with Trend Arrows

Each indicator in the sidebar already shows a bar. Add a trend arrow:

```
Median Income      ████████░░  78th   287,000 SEK   ↑ +8.2%
Employment Rate    ██████░░░░  61st   72.3%         → +1.1%
School Quality     █████████░  91st   242           ↓ -3.4%
Grocery Access     ████████░░  82nd   1.2/1000      — (no trend)
```

**Arrow logic:**
- `↑` green arrow: indicator is improving (respects indicator direction — rising income = improving, falling crime = improving)
- `↓` red/purple arrow: indicator is worsening
- `→` gray arrow: stable (< ±3% change)
- `—` with "(no trend data)" in muted text: insufficient data

**Percentage display:**
- Show the raw percent change, not the normalized change
- "+8.2%" for income means the median income rose 8.2% over the trend period
- This is more meaningful to users than "normalized score moved from 0.73 to 0.78"

### 6.2 Trend Period Label

Above the indicator section, show a subtle label:

```
Indicator Breakdown
Trends based on 2022–2024 data where available
```

### 6.3 Non-Eligible DeSOs

For DeSOs where `trend_eligible = false`:

```
Indicator Breakdown
⚠ Trend data is not available for this area because its statistical
  boundaries were revised in 2025. Current values are shown below.

Median Income      ████████░░  78th   287,000 SEK
Employment Rate    ██████░░░░  61st   72.3%
...
```

No arrows, no percentages. Just current values. Honest.

### 6.4 Tooltip Detail

Hovering/clicking a trend arrow shows a tooltip:

```
┌──────────────────────────────────────┐
│ Median Income Trend                  │
│                                      │
│ 2022: 265,000 SEK                    │
│ 2023: 274,000 SEK                    │
│ 2024: 287,000 SEK                    │
│                                      │
│ Change: +22,000 SEK (+8.3%)          │
│ Confidence: High (3/3 years)         │
└──────────────────────────────────────┘
```

Shows the actual historical values year by year. The user can see the trajectory directly.

If only 2 of 3 years have data:

```
┌──────────────────────────────────────┐
│ School Quality Trend                 │
│                                      │
│ 2022: No data                        │
│ 2023: 248 meritvärde                 │
│ 2024: 242 meritvärde                 │
│                                      │
│ Change: -6 points (-2.4%)            │
│ Confidence: Medium (2/3 years)       │
└──────────────────────────────────────┘
```

### 6.5 No Composite Trend Badge

The score section at the top of the sidebar currently has placeholder space for "Trend badge: ↑ +3.2 or ↓ -1.8". **Remove or hide this.** Don't show a composite trend until the data supports it. Show only the score number and label.

If you want to show *something* in the header, show a subtle text like:

```
Score: 72
Stable / Positive Outlook
See individual indicator trends below ↓
```

---

## Step 7: Confidence Display

### 7.1 Confidence Levels

| Data points | Confidence | Label | Visual |
|---|---|---|---|
| 3/3 years | 1.00 | High | Full arrow, normal weight |
| 2/3 years | 0.67 | Medium | Slightly faded arrow |
| 1/3 years | 0.33 | Low | Don't show trend — too unreliable |
| 0/3 years | 0.00 | None | "—" with "no trend data" |

**Rule: don't show a trend direction if confidence < 0.50.** A single data point gives you a delta between two years but that could be noise. Two or three points is the minimum for a directional claim.

### 7.2 Implementation

In the frontend, filter out low-confidence trends:

```tsx
const showTrend = trend && trend.confidence >= 0.50 && trend.direction !== 'insufficient';
```

---

## Step 8: Handling Methodology Changes

### 8.1 The Problem

If SCB changes how they compute median income (e.g., changes the definition of "disposable income"), a year-over-year comparison is apples-to-oranges. The data jumps not because reality changed but because measurement changed.

### 8.2 Methodology Change Registry

```php
Schema::create('methodology_changes', function (Blueprint $table) {
    $table->id();
    $table->string('source', 40);
    $table->foreignId('indicator_id')->nullable()->constrained();
    $table->integer('year_affected');            // The year the change took effect
    $table->string('change_type', 30);           // 'definition_change', 'calculation_change', 'base_year_change'
    $table->text('description');                  // Human-readable: "SCB changed income definition to include..."
    $table->boolean('breaks_trend')->default(false);  // If true, don't compute trends across this year
    $table->string('source_url')->nullable();     // Link to SCB/Skolverket documentation of the change
    $table->timestamps();
});
```

### 8.3 Usage

When computing trends, if a `methodology_changes` entry with `breaks_trend = true` exists for an indicator and the change year falls within the trend window: **don't compute a trend for that indicator.** Set direction = 'insufficient' with a note.

```php
// In TrendService
$methodologyBreak = MethodologyChange::where('indicator_id', $indicator->id)
    ->where('breaks_trend', true)
    ->whereBetween('year_affected', [$baseYear, $endYear])
    ->exists();

if ($methodologyBreak) {
    // Store as 'insufficient' with note
    return 'insufficient'; // methodology changed within trend window
}
```

### 8.4 When to Populate

The agent should check SCB release notes when fetching historical data. SCB usually documents methodology changes in the table footnotes or in separate PDFs. Note any changes in the `methodology_changes` table as they're discovered.

For now, seed with known changes:
- SCB employment statistics had a methodology change in 2019 ("new time series from 2019")
- Meritvärde calculation has remained stable since 2012 (16-subject system extended to 17 with moderna språk)

---

## Step 9: Pipeline Integration

### 9.1 Extended Pipeline Command

Update `pipeline:run` to include historical data and trends:

```bash
php artisan pipeline:run --with-history --base-year=2022
```

Which adds these stages after scoring:

```php
// After compute:scores...

// Stage: Compute trends
$this->info("=== Stage: Compute Trends ===");
$this->call('compute:trends', [
    '--base-year' => $this->option('base-year') ?? now()->year - 3,
    '--end-year' => now()->year - 1,
]);
```

### 9.2 Historical Ingestion (One-Time)

The historical data fetch is primarily a one-time operation. Once you have 2022–2024 data, subsequent pipeline runs only fetch the new year and add one more year of depth.

```bash
# One-time historical backfill:
php artisan ingest:scb --year=2022
php artisan ingest:scb --year=2023
# 2024 should already be ingested from the current pipeline

php artisan normalize:indicators --year=2022
php artisan normalize:indicators --year=2023

php artisan ingest:skolverket-stats --academic-year=2021/22
php artisan ingest:skolverket-stats --academic-year=2022/23
php artisan aggregate:school-indicators --academic-year=2022/23

php artisan compute:trends --base-year=2022 --end-year=2024
```

---

## Step 10: Verification

### 10.1 Database Checks

```sql
-- Check historical data loaded
SELECT i.slug, iv.year, COUNT(*) AS deso_count
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
GROUP BY i.slug, iv.year
ORDER BY i.slug, iv.year;
-- Should see 3 rows per indicator (2022, 2023, 2024) each with ~5,500-6,160 DeSOs

-- Check DeSO code mapping
SELECT change_type, COUNT(*)
FROM deso_boundary_changes
GROUP BY change_type;

-- Check trend-eligible count
SELECT trend_eligible, COUNT(*)
FROM deso_areas
GROUP BY trend_eligible;
-- Expect: true ~5,000-5,500, false ~600-1,200

-- Check trends computed
SELECT i.slug, it.direction, COUNT(*)
FROM indicator_trends it
JOIN indicators i ON i.id = it.indicator_id
GROUP BY i.slug, it.direction
ORDER BY i.slug;

-- Spot check: Danderyd should have stable or rising income
SELECT it.*, i.slug
FROM indicator_trends it
JOIN indicators i ON i.id = it.indicator_id
JOIN deso_areas da ON da.deso_code = it.deso_code
WHERE da.kommun_name LIKE '%Danderyd%'
  AND i.slug = 'median_income';

-- Areas with biggest positive changes
SELECT it.deso_code, da.kommun_name, i.slug, it.percent_change
FROM indicator_trends it
JOIN indicators i ON i.id = it.indicator_id
JOIN deso_areas da ON da.deso_code = it.deso_code
WHERE i.slug = 'median_income'
ORDER BY it.percent_change DESC LIMIT 10;

-- Verify no trends computed for ineligible DeSOs
SELECT COUNT(*)
FROM indicator_trends it
JOIN deso_areas da ON da.deso_code = it.deso_code
WHERE da.trend_eligible = false;
-- Must be 0
```

### 10.2 Visual Checklist

- [ ] Sidebar shows trend arrows next to each indicator (where data exists)
- [ ] Green ↑ for improving, red ↓ for worsening, gray → for stable
- [ ] Percentage change shown next to arrow (+8.2%, -3.4%, etc.)
- [ ] Indicators without trend data show "—" with "no trend data" label
- [ ] POI indicators show no trend (as expected — current data only)
- [ ] Clicking/hovering trend arrow shows year-by-year historical values
- [ ] Non-trend-eligible DeSOs show explanation message instead of trend arrows
- [ ] No composite trend arrow appears on the map or in the score header
- [ ] Trend period label visible: "Trends based on 2022–2024 data"
- [ ] Danderyd income trend: rising or stable (sanity check)
- [ ] Known gentrifying areas (e.g., Södermalm, Hammarby Sjöstad) show positive trends on multiple indicators

---

## Notes for the Agent

### The DeSO Boundary Change Is the Hardest Part

Don't underestimate this. The SCB Excel file for historical changes may be poorly formatted or ambiguous. The agent may need to:
1. Download both DeSO 2018 and DeSO 2025 boundary GeoPackages from SCB
2. Compare code lists programmatically
3. For codes that exist in both versions, check if the geometries are substantially the same (ST_Equals or area overlap > 95%)
4. For codes that exist only in 2018 or only in 2025, use spatial intersection to determine the relationship

This is a one-time effort but it must be done correctly — wrong code mappings corrupt all trend data.

### Historical SCB API May Use Different Table Paths

Some SCB tables have separate endpoints for different time periods or DeSO versions. When fetching 2022 data, the agent may need to use a different API path than for 2024 data. The 2018-vintage tables are listed separately from 2025-vintage tables in the statistics database.

Check the DeSO tables listing page carefully:
`https://www.scb.se/hitta-statistik/regional-statistik-och-kartor/regionala-indelningar/deso---demografiska-statistikomraden/deso-tabellerna-i-ssd--information-och-instruktioner/`

### Stable Threshold Tuning

The ±3% threshold for "stable" is a starting point. For some indicators it's too tight (income can swing 3-5% just from inflation), for others too loose. Consider making this configurable per indicator in the future. For v1, a single threshold is fine.

### Don't Normalize Historical Data to Current Normalization

When you have 2022 raw values, you could re-normalize them with the 2024 normalization (rank against the 2024 distribution). **Don't do this.** Each year's normalized_value should reflect that year's distribution. The trend is computed on **raw values**, not normalized values.

Comparing "73rd percentile in 2022 vs 78th percentile in 2024" is misleading because the distribution itself may have shifted. Comparing "265,000 SEK in 2022 vs 287,000 SEK in 2024" is honest.

### What NOT to Do

- Don't compute composite trend arrows (decision made: individual indicators only)
- Don't show trends for DeSOs affected by boundary changes
- Don't extrapolate from 1 data point
- Don't normalize historical data using current-year normalization
- Don't compute trends for neutral indicators (population, rental_tenure) — they're context, not scored
- Don't compute trends for POI indicators (no historical data)
- Don't show trend data that contradicts known methodology changes

### What to Prioritize

1. DeSO boundary change detection and code mapping (unblocks everything)
2. Historical SCB data ingestion (3 years back)
3. Trend computation service
4. Sidebar UI — arrows and percentages
5. Trend tooltip with year-by-year values
6. Confidence display
7. Methodology change registry (important but can be populated incrementally)
8. Historical school data (lower priority — school stats are less volatile)

### Future Enhancements (Not This Task)

- Composite trend arrow (when weight coverage threshold is met)
- Mini sparkline chart in sidebar showing 3-5 year trajectory per indicator
- Trend contribution to scoring (rising areas get a bonus, falling areas get a penalty)
- Predictive trend: "based on current trajectory, this area's score in 2027 is estimated at..."
- Trend-based alerts: "3 indicators in DeSO X flipped from rising to falling"