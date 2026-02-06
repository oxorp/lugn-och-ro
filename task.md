# TASK: Data Pipeline + SCB Demographics + Scoring System + Colored Map

## Context

The DeSO boundaries are on the map. Now we need to fill them with data and color them by score. This task establishes the entire data flow pattern that all future data sources will follow, and uses **SCB demographics** as the first connected source.

Read `data_pipeline_specification.md` for full context on all planned data sources. This task only implements the SCB demographic layer, but the architecture must be designed so that adding BRÅ crime data, Skolverket schools, or Kronofogden debt later follows the same pattern with minimal new code.

## Why SCB Demographics First?

SCB demographic data is natively at DeSO level — no spatial transformation, no disaggregation, no regression models. You fetch it, store it, join on `deso_code`, done. It also gives us the most features in a single source (income, employment, education, foreign background, housing type), so we can immediately build a multi-factor score and color the map.

Every other data source is harder: BRÅ is at police district level (needs spatial disaggregation), Kronofogden is at kommun level (needs regression model), Skolverket is point data (needs aggregation). Those come later. Get the pattern right with the easy one first.

---

## Step 1: Data Architecture — The Pattern

### 1.1 Core Principle: Indicators

Every data point in the system is an **Indicator**. An indicator has:

- A unique `slug` (e.g., `median_income`, `employment_rate`, `foreign_background_pct`)
- A human-readable `name` (e.g., "Median Disposable Income")
- A `source` (e.g., `scb`, `bra`, `kronofogden`)
- A `direction`: whether higher values are `positive`, `negative`, or `neutral` for the neighborhood score
- A `weight` (0.0–1.0): how much this indicator contributes to the composite score
- A `unit` (e.g., `SEK`, `percent`, `per_1000`)
- A `normalization_method`: how to scale the raw value to 0–1 (e.g., `min_max`, `z_score`, `rank_percentile`)

The admin dashboard controls weights, directions, and normalization. The scoring engine reads these settings and computes the composite score. This means we can tune the model without deploying code.

### 1.2 Best Practices — READ THIS CAREFULLY

These rules apply to all indicators, all sources, forever:

**Always per capita / per proportion, never absolutes.**
A DeSO with 2,700 people will obviously have more of everything than one with 700 people. Every metric must be normalized by population. SCB usually provides this already (percentages, medians), but if we ever ingest count data, we divide by population before storing. The `indicator_values` table stores the normalized value, not the raw count.

**Always store the raw value too.**
Keep the original value from the source alongside the normalized one. We'll need it for debugging, validation, and display ("Median income: 287,000 SEK" not "Income score: 0.64").

**Always store the year.**
Every indicator value is tied to a specific year. The scoring engine uses the most recent year by default but can compute trends by comparing across years.

**Always track data freshness.**
Every ingestion run logs when it happened, what it fetched, and whether it succeeded. The frontend can show "Data last updated: 2024-03-15" per indicator.

**Percentile rank is the default normalization.**
Min-max is sensitive to outliers (one extremely rich DeSO skews everything). Z-score assumes normal distribution. **Percentile rank** is the most robust default: "this DeSO is in the 73rd percentile for median income" works regardless of distribution shape. Use rank percentile unless there's a specific reason not to.

**Direction determines sign in the composite score.**
`positive` means higher values → higher score (income, employment, education). `negative` means higher values → lower score (crime rate, debt rate). `neutral` means include in the data but don't score it (population count, area size).

**The agent should update CLAUDE.md with any new best practices discovered during implementation.** If you find something that works well or a gotcha to avoid, add it to the best practices section in CLAUDE.md so future tasks benefit.

---

## Step 2: Database Migrations

### 2.1 Indicators Table

```php
Schema::create('indicators', function (Blueprint $table) {
    $table->id();
    $table->string('slug', 80)->unique();          // e.g., "median_income"
    $table->string('name');                          // "Median Disposable Income"
    $table->string('description')->nullable();       // Longer explanation
    $table->string('source', 40);                    // "scb", "bra", "kronofogden"
    $table->string('source_table')->nullable();      // SCB table ID for reference
    $table->string('unit', 40)->default('number');   // "SEK", "percent", "per_1000", "number"
    $table->enum('direction', ['positive', 'negative', 'neutral'])->default('neutral');
    $table->decimal('weight', 5, 4)->default(0.0);  // 0.0000 to 1.0000
    $table->string('normalization', 40)->default('rank_percentile'); // rank_percentile, min_max, z_score
    $table->boolean('is_active')->default(true);     // Can disable without deleting
    $table->integer('display_order')->default(0);
    $table->string('category')->nullable();          // "demographics", "income", "education", "employment"
    $table->timestamps();
});
```

### 2.2 Indicator Values Table

This is the big table — one row per DeSO per indicator per year.

```php
Schema::create('indicator_values', function (Blueprint $table) {
    $table->id();
    $table->string('deso_code', 10)->index();
    $table->foreignId('indicator_id')->constrained()->index();
    $table->integer('year')->index();
    $table->decimal('raw_value', 14, 4)->nullable();        // Original value from source
    $table->decimal('normalized_value', 8, 6)->nullable();   // 0.000000 to 1.000000 after normalization
    $table->timestamps();

    $table->unique(['deso_code', 'indicator_id', 'year']);   // One value per DeSO per indicator per year
    $table->index(['indicator_id', 'year']);                  // For normalization queries
});
```

For ~6,160 DeSOs × ~10 indicators × ~5 years = ~308,000 rows initially. Small.

### 2.3 Composite Scores Table

Pre-computed composite score per DeSO per year. Recomputed whenever weights change or new data arrives.

```php
Schema::create('composite_scores', function (Blueprint $table) {
    $table->id();
    $table->string('deso_code', 10)->index();
    $table->integer('year');
    $table->decimal('score', 6, 2);                   // 0.00 to 100.00
    $table->decimal('trend_1y', 6, 2)->nullable();     // Change vs previous year
    $table->decimal('trend_3y', 6, 2)->nullable();     // Change vs 3 years ago
    $table->json('factor_scores')->nullable();          // {"median_income": 0.73, "employment_rate": 0.61, ...}
    $table->json('top_positive')->nullable();           // ["rising_income", "high_education"]
    $table->json('top_negative')->nullable();           // ["low_employment", "declining_income"]
    $table->timestamp('computed_at');
    $table->timestamps();

    $table->unique(['deso_code', 'year']);
});
```

### 2.4 Ingestion Logs Table

```php
Schema::create('ingestion_logs', function (Blueprint $table) {
    $table->id();
    $table->string('source', 40);             // "scb", "bra", etc.
    $table->string('command');                 // "ingest:scb"
    $table->string('status', 20);             // "running", "completed", "failed"
    $table->integer('records_processed')->default(0);
    $table->integer('records_created')->default(0);
    $table->integer('records_updated')->default(0);
    $table->text('error_message')->nullable();
    $table->json('metadata')->nullable();     // Any extra info about the run
    $table->timestamp('started_at');
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();
});
```

---

## Step 3: Seed the Indicators

Create a seeder (or a migration with inserts) for the initial SCB demographic indicators. These are the DeSO-level variables we want to ingest first:

| slug | name | unit | direction | weight | category |
|---|---|---|---|---|---|
| `median_income` | Median Disposable Income | SEK | positive | 0.15 | income |
| `low_economic_standard_pct` | Low Economic Standard (%) | percent | negative | 0.10 | income |
| `employment_rate` | Employment Rate (16-64) | percent | positive | 0.10 | employment |
| `education_post_secondary_pct` | Post-Secondary Education (%) | percent | positive | 0.10 | education |
| `education_below_secondary_pct` | Below Secondary Education (%) | percent | negative | 0.05 | education |
| `foreign_background_pct` | Foreign Background (%) | percent | neutral | 0.00 | demographics |
| `population` | Population | number | neutral | 0.00 | demographics |
| `rental_tenure_pct` | Rental Housing (%) | percent | neutral | 0.00 | housing |

**Notes on weights:**
- Total active weights should sum to 1.0 across ALL indicators (including future sources). Right now we only have SCB, so the weights above sum to 0.50. The remaining 0.50 is reserved for crime (BRÅ), schools (Skolverket), debt (Kronofogden), and POIs. This is fine — the scoring engine normalizes by dividing by the sum of active weights.
- `foreign_background_pct` is set to `neutral` with weight 0.00. It's stored for analysis and the disaggregation model but does NOT contribute to the visible score. This is a deliberate design decision (see legal constraints in data_pipeline_specification.md).
- `population` and `rental_tenure_pct` are also neutral/zero-weight. Useful context, not scoring factors.

---

## Step 4: SCB Data Ingestion Command

### 4.1 SCB PX-Web API

SCB has two API versions. Use the **v1 API** (it still works and has more documentation):

**Base URL:** `https://api.scb.se/OV0104/v1/doris/en/ssd/`

The API uses POST requests with a JSON body specifying which variables and time periods you want. It returns JSON-stat2 format.

**Important:** SCB has a limit of **150,000 data cells per request** and **30 requests per 10 seconds** per IP. DeSO-level data for all 6,160 areas × 1 year × 1 variable is well within limits. But if fetching multiple years, batch by year.

There is also a **v2 API** launched October 2025 at `https://statistikdatabasen.scb.se/api/v2/` which uses GET instead of POST. If v1 gives trouble, try v2. But v1 has much more documentation and examples online.

### 4.2 Tables to Fetch

The agent needs to explore the SCB API to find the exact table IDs for DeSO-level data. Start by browsing:

```
GET https://api.scb.se/OV0104/v1/doris/en/ssd/HE/HE0110/
```

This lists available income tables. Look for tables with "DeSO" or "region" as a dimension where DeSO codes appear.

**Key tables to look for (the agent should navigate the API to find exact paths):**

| Data | Expected SCB path area | Notes |
|---|---|---|
| Income (median, distribution) | `HE/HE0110/` | Look for tables with DeSO regional breakdown |
| Employment status (16-64) | `AM/AM0207/` | Sysselsättning by region |
| Education level (25-64) | `UF/UF0506/` | Utbildningsnivå by region |
| Population by background | `BE/BE0101/` | Foreign/Swedish background by DeSO |
| Housing / tenure type | `BO/BO0104/` | Upplåtelseform by DeSO |

**How to find the right table:** Browse the SCB statistics database at https://www.statistikdatabasen.scb.se and find a DeSO-level table. At the bottom of the table page, there's often a link "Make a table available in your application" or "API för denna tabell" that gives the exact API URL.

**Alternatively:** The DeSO tables index page at SCB lists all available DeSO tables:
https://www.scb.se/hitta-statistik/regional-statistik-och-kartor/regionala-indelningar/demografiska-statistikomraden-deso/deso-tabellerna-i-ssd--information-och-instruktioner/

### 4.3 Artisan Command Structure

Create `app/Console/Commands/IngestScbData.php`:

```bash
php artisan ingest:scb [--indicator=median_income] [--year=2023] [--all]
```

The command should:

1. **Create an ingestion log** entry with status "running"
2. **Determine which indicators to fetch** (all active SCB indicators, or a specific one)
3. **For each indicator:**
   a. Build the PX-Web API request body
   b. POST to the SCB API
   c. Parse the JSON-stat2 response
   d. Map each DeSO code → raw value
   e. Upsert into `indicator_values` (raw_value for now; normalized_value comes in Step 5)
4. **Update the ingestion log** with counts and status
5. **Trigger normalization** after all indicators are ingested (Step 5)

**JSON-stat2 parsing:** The response format is a bit unusual. It looks like:

```json
{
  "id": ["Region", "ContentsCode", "Tid"],
  "size": [6160, 1, 1],
  "dimension": {
    "Region": {
      "category": {
        "index": {"0114A0010": 0, "0114A0020": 1, ...},
        "label": {"0114A0010": "Upplands Väsby 0010", ...}
      }
    }
  },
  "value": [287000, 265000, 310000, ...]
}
```

The `value` array is flat and corresponds to the cartesian product of all dimensions in order. Since we're fetching one variable for one year, the array is just DeSO values in order. Map them back via the dimension index.

**Build a reusable `ScbApiService` class** that handles:
- Building PX-Web POST requests
- Rate limiting (max 30 requests per 10 seconds)
- Parsing JSON-stat2 responses into `[deso_code => value]` arrays
- Error handling and retries

### 4.4 Handle DeSO 2025 vs 2018

SCB is transitioning from DeSO 2018 (5,984 areas) to DeSO 2025 (6,160 areas). Some tables may still be on the old version. The command should:
- Try to match DeSO codes from the API response to our `deso_areas` table
- Log any codes that don't match (these are likely old DeSO 2018 codes)
- Continue gracefully — not every DeSO needs to have every indicator

---

## Step 5: Normalization Service

### 5.1 The Normalization Step

After raw values are ingested, compute the `normalized_value` for each row. This is a separate step because normalization is relative — it depends on all DeSOs' values for that indicator.

Create `app/Services/NormalizationService.php`:

```php
public function normalizeIndicator(Indicator $indicator, int $year): void
{
    // Fetch all raw values for this indicator + year
    // Apply the indicator's normalization_method
    // Update normalized_value for each row
}
```

### 5.2 Percentile Rank Normalization (Default)

For each indicator + year:

1. Fetch all non-null `raw_value` entries
2. Sort ascending
3. Each DeSO's normalized value = its rank / total count (0.0 to 1.0)
4. Ties get the average rank

In SQL this is efficient:

```sql
UPDATE indicator_values iv
SET normalized_value = sub.percentile
FROM (
    SELECT id,
           PERCENT_RANK() OVER (ORDER BY raw_value) as percentile
    FROM indicator_values
    WHERE indicator_id = ? AND year = ? AND raw_value IS NOT NULL
) sub
WHERE iv.id = sub.id;
```

For `negative` direction indicators, the percentile is inverted: `1.0 - percentile`. This way, a high crime rate gets a LOW normalized value (bad for the score), and all normalized values have the same semantics: **higher = better for the neighborhood**.

**Important:** Do the inversion in the scoring step, not in the normalization step. Store the raw percentile in `normalized_value`. The scoring engine applies direction. This keeps normalization pure and direction logic in one place.

### 5.3 Run After Ingestion

The ingestion command should call normalization automatically after data is loaded:

```php
$this->call('normalize:indicators', ['--year' => $year]);
```

Or make normalization part of the ingestion flow. Either way, after `ingest:scb` finishes, all `normalized_value` fields should be populated.

---

## Step 6: Scoring Engine

### 6.1 Composite Score Computation

Create `app/Services/ScoringService.php`:

```php
public function computeScores(int $year): void
{
    // 1. Fetch all active indicators with weight > 0
    // 2. For each DeSO:
    //    a. Fetch normalized_value for each active indicator
    //    b. Apply direction (invert if negative)
    //    c. Weighted sum: score = Σ(weight_i × directed_value_i) / Σ(weight_i)
    //    d. Scale to 0-100
    //    e. Compute trends (compare to previous years if available)
    //    f. Identify top positive and negative factors
    // 3. Upsert into composite_scores
}
```

### 6.2 Direction Application

```php
$directedValue = match($indicator->direction) {
    'positive' => $normalizedValue,           // High income = good
    'negative' => 1.0 - $normalizedValue,     // High crime = bad
    'neutral' => null,                         // Skip in scoring
};
```

### 6.3 Factor Identification

For each DeSO, after computing the composite score, identify which factors are pulling it up or down:

- `top_positive`: indicators where the DeSO's directed value is above 0.7 (top 30%)
- `top_negative`: indicators where the DeSO's directed value is below 0.3 (bottom 30%)

Store these as JSON arrays with indicator slugs.

### 6.4 Artisan Command

```bash
php artisan compute:scores [--year=2023]
```

This reads all active indicators, computes composite scores, and writes to `composite_scores`.

---

## Step 7: Admin Dashboard — Indicator Management

### 7.1 Route & Controller

Create admin routes (no auth for now — we'll add admin role later):

```php
Route::prefix('admin')->group(function () {
    Route::get('/indicators', [AdminIndicatorController::class, 'index'])->name('admin.indicators');
    Route::put('/indicators/{indicator}', [AdminIndicatorController::class, 'update'])->name('admin.indicators.update');
    Route::post('/recompute-scores', [AdminScoreController::class, 'recompute'])->name('admin.recompute');
});
```

### 7.2 Admin Indicators Page

An Inertia page at `/admin/indicators` that shows a table of all indicators with editable fields:

| Column | Editable? | Control |
|---|---|---|
| Name | No | Display only |
| Slug | No | Display only |
| Source | No | Badge |
| Category | No | Badge |
| Direction | Yes | Select: positive / negative / neutral |
| Weight | Yes | Number input (0.00 to 1.00, step 0.01) |
| Normalization | Yes | Select: rank_percentile / min_max / z_score |
| Active | Yes | Toggle switch |
| Latest Year | No | Display (from most recent indicator_values) |
| Coverage | No | Display (count of DeSOs with data / total DeSOs) |

Use shadcn `Table`, `Select`, `Input`, `Switch`, `Badge` components.

### 7.3 Weight Bar

Show a visual bar at the top of the page showing total weight allocation:

```
[███████████████░░░░░░░░░░░░░░░] 50% allocated (0.50 / 1.00)
 Income: 0.25  Employment: 0.10  Education: 0.15  [unallocated: 0.50]
```

This helps the admin understand how much weight budget remains for future data sources (crime, schools, debt).

### 7.4 Recompute Button

A button at the top: "Recompute All Scores". When clicked:
1. Dispatches a Laravel job to recompute all composite scores
2. Shows a loading state
3. When done, shows "Scores recomputed for X DeSOs"

The admin can change weights, toggle indicators, then hit recompute to see the map update.

---

## Step 8: Colored Map

### 8.1 Score API Endpoint

Create an endpoint that returns composite scores for the map layer:

```php
Route::get('/api/deso/scores', [DesoController::class, 'scores']);
```

```php
public function scores(Request $request)
{
    $year = $request->integer('year', now()->year - 1);  // Default to most recent full year

    $scores = DB::table('composite_scores')
        ->where('year', $year)
        ->select('deso_code', 'score', 'trend_1y')
        ->get()
        ->keyBy('deso_code');

    return response()->json($scores)
        ->header('Cache-Control', 'public, max-age=3600');
}
```

This returns a map of `deso_code → {score, trend_1y}` that the frontend joins with the GeoJSON geometries.

### 8.2 Color Scale

The map should color each DeSO polygon on a continuous gradient:

| Score | Color | Hex |
|---|---|---|
| 0 | Deep purple | `#4a0072` |
| 25 | Red-purple | `#9c1d6e` |
| 50 | Warm yellow | `#f0c040` |
| 75 | Light green | `#6abf4b` |
| 100 | Deep green | `#1a7a2e` |

Use a smooth interpolation between these stops. In OpenLayers, set the fill color of each feature's style based on its score.

**Implementation:**
1. Fetch `/api/deso/geojson` for geometries (existing endpoint from task 1)
2. Fetch `/api/deso/scores` for scores
3. Join on `deso_code`
4. Style each feature: `fill = interpolateColor(score)`
5. DeSOs with no score data: style with a neutral gray (`#cccccc`) and a dashed border

### 8.3 Legend

Add a color scale legend overlay on the map (bottom-left or bottom-right). Show the gradient bar with labels:

```
High Risk ─────── Mixed ─────── Strong Growth
[purple]        [yellow]         [green]
```

Use a simple HTML/CSS gradient bar positioned over the map.

### 8.4 Updated Click Panel

When a DeSO is clicked, the sidebar should now show:

- DeSO code + name
- Kommun + Län
- **Composite score** (big number, colored by the same scale)
- **Trend arrow** (↑ ↓ →) with 1-year change value
- **Factor breakdown**: list each active indicator with its individual score bar
  - e.g., "Median Income: ████████░░ 78th percentile (287,000 SEK)"
  - e.g., "Employment Rate: ██████░░░░ 61st percentile (72.3%)"
- **Top strengths** (green badges)
- **Top weaknesses** (red/purple badges)

---

## Step 9: Full Pipeline Test

### 9.1 End-to-End Run

```bash
# 1. Ingest SCB data
php artisan ingest:scb --all

# 2. Normalize (should happen automatically, but verify)
php artisan normalize:indicators --year=2023

# 3. Compute composite scores
php artisan compute:scores --year=2023
```

### 9.2 Sanity Check Queries

```sql
-- Check indicator coverage
SELECT i.slug, COUNT(iv.id) as deso_count,
       MIN(iv.raw_value), MAX(iv.raw_value), ROUND(AVG(iv.raw_value)::numeric, 1)
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
WHERE iv.year = 2023
GROUP BY i.slug;

-- Check normalized values are in 0-1 range
SELECT MIN(normalized_value), MAX(normalized_value), AVG(normalized_value)
FROM indicator_values
WHERE year = 2023 AND normalized_value IS NOT NULL;

-- Top 10 highest-scoring DeSOs (should be affluent areas)
SELECT cs.deso_code, da.kommun_name, cs.score
FROM composite_scores cs
JOIN deso_areas da ON da.deso_code = cs.deso_code
WHERE cs.year = 2023
ORDER BY cs.score DESC LIMIT 10;
-- Expect: Danderyd, Lidingö, Täby, Lomma, Vellinge type areas

-- Bottom 10 (should be known disadvantaged areas)
SELECT cs.deso_code, da.kommun_name, cs.score
FROM composite_scores cs
JOIN deso_areas da ON da.deso_code = cs.deso_code
WHERE cs.year = 2023
ORDER BY cs.score ASC LIMIT 10;
-- Expect: areas in Malmö, Gothenburg (Angered), Stockholm (Rinkeby/Tensta)
```

### 9.3 Visual Checklist

- [ ] Map loads with colored DeSO polygons (purple to green gradient)
- [ ] Clear color differentiation visible across Sweden
- [ ] Stockholm inner city greener than outer suburbs
- [ ] Danderyd, Lidingö clearly green
- [ ] Known vulnerable areas (Rinkeby, Rosengård, Angered) clearly purple
- [ ] Clicking a DeSO shows score breakdown with indicator bars
- [ ] Gray DeSOs (no data) are distinguishable with dashed borders
- [ ] Admin page at `/admin/indicators` shows all indicators with weights
- [ ] Changing a weight and clicking "Recompute" changes the map colors
- [ ] Legend on the map shows the purple-to-green scale

---

## Notes for the Agent

### Exploring the SCB API

The SCB PX-Web API is navigable. Start at the base URL and drill down:

```
GET https://api.scb.se/OV0104/v1/doris/en/ssd/
```

Returns a list of subject areas. Each level returns either more levels or a table. When you reach a table, a GET returns its metadata (dimensions, variables, time periods). A POST with a query body returns data.

**To discover DeSO tables:** The region dimension will have values like `"0114A0010"` (DeSO codes) instead of `"01"` (län) or `"0114"` (kommun). If the region values are 9-character codes starting with a 4-digit kommun code followed by a letter and 4 digits, that's a DeSO table.

The DeSO tables index page at SCB lists everything available:
https://www.scb.se/hitta-statistik/regional-statistik-och-kartor/regionala-indelningar/demografiska-statistikomraden-deso/deso-tabellerna-i-ssd--information-och-instruktioner/

### What NOT to do

- Don't hardcode DeSO codes or indicator weights in the scoring service — always read from the database
- Don't store absolute counts without per-capita normalization
- Don't pre-compute colors on the backend — send scores to the frontend, let OpenLayers handle styling
- Don't add authentication to admin routes yet — future task
- Don't ingest crime, schools, or debt data — only SCB demographics
- Don't apply direction inversion during normalization — do it in the scoring engine

### What to prioritize

- Get ONE indicator (`median_income`) flowing end-to-end first: API → database → normalize → score → colored hex on map
- Once the pipe works for one, add the rest
- The admin dashboard matters — it's how we tune the model
- The sanity check (Danderyd green, Rinkeby purple) is the definition of done

### Updating CLAUDE.md

Add a "Best Practices" section to CLAUDE.md with anything you learn: API quirks, JSON-stat2 parsing gotchas, normalization edge cases, performance tips. Future agents will thank you.