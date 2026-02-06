# TASK: Data Quality & Governance Framework

## Context

The platform currently has 2 data sources (SCB, Skolverket) with 3 more planned (BRÅ, Kronofogden, POIs). Each new source multiplies the surface area for things to go wrong: schema changes upstream, silent data gaps, regression model drift, coordinate errors, and normalization artifacts. Right now, if SCB publishes a table with a changed column name, or Skolverket returns null meritvärde for 2,000 schools, or the Kronofogden disaggregation model predicts negative debt rates — the pipeline would silently produce garbage scores, and you'd discover it by noticing that Danderyd turned purple.

That doesn't work when you have paying customers. It especially doesn't work when a mäklare shows a client your score in a bidding war, or when a bank uses your data for mortgage risk decisions. The first time a customer catches an obvious error, you lose trust permanently.

This task builds the immune system: validation rules, sanity checks, anomaly detection, audit trails, rollback capability, and a data quality dashboard. It's designed to scale — adding a new data source should inherit the framework automatically, not require custom validation logic each time.

**This is also a sales asset.** "How do you ensure data quality?" is one of the first questions banks, insurance companies, and enterprise clients ask. Having a documented, automated quality framework makes the difference between "interesting startup" and "we can write a procurement contract."

## Goals

1. Validation rules that run on every data ingestion (per-source, per-indicator)
2. Post-scoring sanity checks ("sentinel areas" that must stay within expected ranges)
3. Anomaly detection for score shifts between computation runs
4. A complete audit trail: which data version produced which scores
5. Rollback capability: restore previous scores if a bad ingestion is detected
6. A data quality dashboard (admin) showing health status across all sources
7. Alerting when something fails or looks wrong

---

## Step 1: Validation Rule Engine

### 1.1 The Concept

Every indicator has a set of validation rules that define what "acceptable data" looks like. When ingestion runs, every incoming value is checked against these rules. Values that fail are flagged, logged, and optionally excluded from scoring.

This is not complex — it's a set of range checks, completeness checks, and consistency checks stored in the database and applied automatically.

### 1.2 Validation Rules Table

```php
Schema::create('validation_rules', function (Blueprint $table) {
    $table->id();
    $table->foreignId('indicator_id')->nullable()->constrained();  // NULL = applies to all indicators for this source
    $table->string('source', 40)->nullable();         // 'scb', 'skolverket', etc. NULL = global rule
    $table->string('rule_type', 40);                  // 'range', 'completeness', 'consistency', 'change_rate', 'distribution'
    $table->string('name');                           // Human-readable: "Median income must be positive"
    $table->string('severity', 20)->default('warning'); // 'error', 'warning', 'info'
    $table->json('parameters');                        // Rule-specific params (see below)
    $table->boolean('is_active')->default(true);
    $table->boolean('blocks_scoring')->default(false); // If true, failed rule prevents score computation
    $table->text('description')->nullable();
    $table->timestamps();
});
```

### 1.3 Rule Types

**Range check** — value must be within expected bounds:
```json
{
    "rule_type": "range",
    "parameters": {
        "min": 0,
        "max": 2000000,
        "unit_context": "SEK — median income should never be negative or above 2M"
    }
}
```

**Completeness check** — minimum percentage of DeSOs must have data:
```json
{
    "rule_type": "completeness",
    "parameters": {
        "min_coverage_pct": 80,
        "context": "At least 80% of DeSOs should have median income data"
    }
}
```

**Change rate check** — value shouldn't change more than X% between years:
```json
{
    "rule_type": "change_rate",
    "parameters": {
        "max_change_pct": 50,
        "comparison": "previous_year",
        "context": "Median income shouldn't jump 50% year-over-year for any DeSO"
    }
}
```

**Distribution check** — the overall distribution should look reasonable:
```json
{
    "rule_type": "distribution",
    "parameters": {
        "expected_mean_min": 180000,
        "expected_mean_max": 350000,
        "expected_stddev_min": 30000,
        "expected_stddev_max": 150000,
        "context": "National income distribution should be within known ranges"
    }
}
```

**Spatial consistency check** — neighboring DeSOs shouldn't differ wildly (for indicators that should be spatially smooth):
```json
{
    "rule_type": "spatial_consistency",
    "parameters": {
        "max_neighbor_diff_pct": 200,
        "check_type": "average_neighbor",
        "context": "A DeSO's income shouldn't be 3x its neighbor average — possible data error"
    }
}
```

### 1.4 Seeder — Initial Rules

Seed rules for all existing indicators. Examples:

| indicator_slug | rule_type | severity | parameters |
|---|---|---|---|
| median_income | range | error | min: 0, max: 2,000,000 |
| median_income | completeness | warning | min_coverage_pct: 85 |
| median_income | change_rate | warning | max_change_pct: 40 |
| median_income | distribution | warning | expected_mean: 200K–320K |
| employment_rate | range | error | min: 0, max: 100 |
| employment_rate | completeness | warning | min_coverage_pct: 85 |
| school_merit_value_avg | range | error | min: 50, max: 340 |
| school_merit_value_avg | completeness | info | min_coverage_pct: 30 (many DeSOs lack schools) |
| school_teacher_certification_avg | range | error | min: 0, max: 100 |
| low_economic_standard_pct | range | error | min: 0, max: 100 |
| education_post_secondary_pct | range | error | min: 0, max: 100 |
| population | range | error | min: 1, max: 50000 |

**Global rules** (apply to all indicators):
- Every ingestion must process at least 1,000 DeSOs (prevents accidental partial imports)
- No indicator should have 100% identical values across all DeSOs (suggests parsing error)
- No indicator should have more than 20% NULL values if it previously had < 5% (suggests source schema change)

### 1.5 Validation Results Table

```php
Schema::create('validation_results', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ingestion_log_id')->constrained();
    $table->foreignId('validation_rule_id')->constrained();
    $table->string('status', 20);            // 'passed', 'failed', 'skipped'
    $table->json('details')->nullable();      // Specifics: which DeSOs failed, actual vs expected values
    $table->integer('affected_count')->default(0);  // How many DeSOs/records were affected
    $table->text('message')->nullable();      // Human-readable summary
    $table->timestamps();

    $table->index(['ingestion_log_id', 'status']);
});
```

### 1.6 Validation Service

Create `app/Services/DataValidationService.php`:

```php
class DataValidationService
{
    public function validateIngestion(IngestionLog $log, string $source, int $year): ValidationReport
    {
        $rules = ValidationRule::where(function ($q) use ($source) {
            $q->where('source', $source)->orWhereNull('source');
        })->where('is_active', true)->get();

        $results = [];
        foreach ($rules as $rule) {
            $result = $this->evaluateRule($rule, $source, $year);
            $results[] = $result;

            ValidationResult::create([
                'ingestion_log_id' => $log->id,
                'validation_rule_id' => $rule->id,
                'status' => $result->status,
                'details' => $result->details,
                'affected_count' => $result->affectedCount,
                'message' => $result->message,
            ]);
        }

        return new ValidationReport($results);
    }

    public function hasBlockingFailures(ValidationReport $report): bool
    {
        return $report->results->contains(fn ($r) =>
            $r->status === 'failed' && $r->rule->blocks_scoring
        );
    }
}
```

### 1.7 Integration with Ingestion Commands

Every ingestion command should run validation automatically after data is loaded:

```php
// In IngestScbData command, after upserting indicator_values:
$report = app(DataValidationService::class)->validateIngestion($log, 'scb', $year);

if ($report->hasBlockingFailures()) {
    $this->error("Blocking validation failures detected. Scoring will not proceed.");
    $this->error($report->summary());
    $log->update(['status' => 'completed_with_errors']);
    return Command::FAILURE;
}

if ($report->hasWarnings()) {
    $this->warn("Validation warnings:");
    $this->warn($report->summary());
}

$log->update(['status' => 'completed', 'metadata' => $report->toArray()]);
```

---

## Step 2: Sentinel Areas (Sanity Checks)

### 2.1 The Concept

Certain DeSOs have well-known, stable characteristics. If their score changes dramatically between computation runs, something is wrong with the data, not the neighborhood. These are "sentinel areas" — canaries in the coal mine.

### 2.2 Sentinel Areas Table

```php
Schema::create('sentinel_areas', function (Blueprint $table) {
    $table->id();
    $table->string('deso_code', 10)->index();
    $table->string('name');                           // Human-readable: "Danderyd centrum"
    $table->string('expected_tier', 20);              // 'top', 'upper', 'middle', 'lower', 'bottom'
    $table->decimal('expected_score_min', 6, 2);      // e.g., 70
    $table->decimal('expected_score_max', 6, 2);      // e.g., 95
    $table->text('rationale')->nullable();             // Why this area is a sentinel
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

### 2.3 Seed Sentinel Areas

These should be areas whose scores are obvious and stable. Pick from both ends:

**Top sentinels (must score high — if they don't, something is broken):**

| name | kommun | expected_score | rationale |
|---|---|---|---|
| Danderyd centrum | Danderyd | 75–95 | Sweden's wealthiest municipality. Consistently top income, schools, employment. |
| Lidingö inner | Lidingö | 70–92 | Affluent island suburb of Stockholm. High income, excellent schools. |
| Lomma | Lomma | 70–90 | Wealthy Skåne kommun. Top schools, high income. |
| Hovås/Askim | Göteborg | 70–90 | Affluent area in south Gothenburg. |

**Bottom sentinels (must score low — if they don't, something is broken):**

| name | kommun | expected_score | rationale |
|---|---|---|---|
| Rinkeby | Stockholm | 5–30 | One of Sweden's most disadvantaged areas. Police "särskilt utsatt." |
| Rosengård | Malmö | 5–30 | Known vulnerable area. Low income, low employment, low school results. |
| Angered (Hjällbo/Hammarkullen) | Göteborg | 5–35 | Gothenburg's most disadvantaged areas. |
| Vivalla | Örebro | 10–35 | Smaller city vulnerable area. |

**Middle sentinels (should be average — detects uniform distribution bugs):**

| name | kommun | expected_score | rationale |
|---|---|---|---|
| Typical mid-size town center | Various | 40–65 | Average Swedish town should score mid-range |

**Note:** The agent will need to find the actual DeSO codes for these areas. Use the SCB REGINA tool (regina.scb.se) or query the database for DeSOs in these kommuner.

### 2.4 Sentinel Check Command

```bash
php artisan check:sentinels [--year=2024]
```

Runs after every score computation. For each sentinel:
1. Look up the computed composite score
2. Compare to expected range
3. If outside range: log a warning/error with the deviation

```php
class CheckSentinels extends Command
{
    public function handle()
    {
        $year = $this->option('year') ?? now()->year - 1;
        $sentinels = SentinelArea::where('is_active', true)->get();
        $failures = 0;

        foreach ($sentinels as $sentinel) {
            $score = CompositeScore::where('deso_code', $sentinel->deso_code)
                ->where('year', $year)
                ->value('score');

            if ($score === null) {
                $this->warn("⚠ {$sentinel->name}: No score computed");
                $failures++;
                continue;
            }

            if ($score < $sentinel->expected_score_min || $score > $sentinel->expected_score_max) {
                $this->error("✗ {$sentinel->name}: Score {$score} outside expected range [{$sentinel->expected_score_min}–{$sentinel->expected_score_max}]");
                $failures++;
            } else {
                $this->info("✓ {$sentinel->name}: Score {$score} (expected {$sentinel->expected_score_min}–{$sentinel->expected_score_max})");
            }
        }

        if ($failures > 0) {
            $this->error("{$failures} sentinel check(s) failed. Review before publishing scores.");
            // Log to validation_results or a dedicated sentinel_results table
            return Command::FAILURE;
        }

        $this->info("All sentinel checks passed.");
        return Command::SUCCESS;
    }
}
```

### 2.5 Integration with Score Pipeline

```bash
php artisan compute:scores --year=2024
php artisan check:sentinels --year=2024  # ← Must pass before scores go live
```

If sentinels fail, scores are computed but NOT published to the live API. They stay in a "pending" state until reviewed.

---

## Step 3: Score Versioning & Audit Trail

### 3.1 The Problem

Right now, `composite_scores` is overwritten each time scores are recomputed. If a bad data ingestion produces wrong scores and you overwrite the good ones, there's no way to get them back.

### 3.2 Score Versions Table

```php
Schema::create('score_versions', function (Blueprint $table) {
    $table->id();
    $table->integer('year');
    $table->string('status', 20)->default('pending');  // 'pending', 'validated', 'published', 'rolled_back'
    $table->json('indicators_used');                     // Snapshot of which indicators + weights were active
    $table->json('ingestion_log_ids');                   // Which ingestions contributed to this version
    $table->json('validation_summary');                  // Summary of all validation results
    $table->json('sentinel_results');                    // Sentinel check outcomes
    $table->integer('deso_count')->default(0);           // How many DeSOs have scores
    $table->decimal('mean_score', 6, 2)->nullable();
    $table->decimal('stddev_score', 6, 2)->nullable();
    $table->string('computed_by')->nullable();           // 'system', 'admin:user@example.com'
    $table->text('notes')->nullable();                   // Admin can add notes: "Recomputed after Kronofogden update"
    $table->timestamp('computed_at');
    $table->timestamp('published_at')->nullable();
    $table->timestamps();
});
```

### 3.3 Update Composite Scores to Include Version

Add to `composite_scores`:

```php
$table->foreignId('score_version_id')->nullable()->constrained();
```

### 3.4 Score Lifecycle

```
Ingestion → Validation → Score Computation → Sentinel Check → Review → Publish
                                                                  ↓
                                                          (if problems)
                                                              Rollback
```

**States:**
1. **Pending** — Scores computed but not yet validated
2. **Validated** — Sentinel checks passed, no blocking validation failures
3. **Published** — Scores are live on the public API/map
4. **Rolled back** — A problem was found, previous version restored

### 3.5 Publish/Rollback Commands

```bash
# Publish a validated version (makes it the active version for the API)
php artisan scores:publish --version=42

# Rollback to a previous version
php artisan scores:rollback --to-version=41 --reason="Skolverket data had null meritvärde for 2000 schools"
```

### 3.6 API Serves Published Version Only

Update the scores API to only serve the latest published version:

```php
public function scores(Request $request)
{
    $year = $request->integer('year', now()->year - 1);

    $publishedVersion = ScoreVersion::where('year', $year)
        ->where('status', 'published')
        ->latest('published_at')
        ->first();

    if (!$publishedVersion) {
        return response()->json(['error' => 'No published scores available'], 404);
    }

    $scores = CompositeScore::where('score_version_id', $publishedVersion->id)
        ->select('deso_code', 'score', 'trend_1y')
        ->get()
        ->keyBy('deso_code');

    return response()->json($scores);
}
```

---

## Step 4: Anomaly Detection

### 4.1 Score Drift Detection

When new scores are computed, automatically compare them to the previously published version:

```php
class ScoreDriftDetector
{
    public function detect(ScoreVersion $newVersion, ScoreVersion $previousVersion): DriftReport
    {
        $drifts = DB::select("
            SELECT
                new.deso_code,
                old.score AS old_score,
                new.score AS new_score,
                new.score - old.score AS drift,
                ABS(new.score - old.score) AS abs_drift
            FROM composite_scores new
            JOIN composite_scores old
                ON old.deso_code = new.deso_code
            WHERE new.score_version_id = ?
              AND old.score_version_id = ?
            ORDER BY abs_drift DESC
        ", [$newVersion->id, $previousVersion->id]);

        return new DriftReport(
            totalAreas: count($drifts),
            meanDrift: avg of abs_drift,
            maxDrift: max abs_drift,
            areasWithLargeDrift: drifts where abs_drift > threshold,
            driftDistribution: histogram of drift values,
        );
    }
}
```

### 4.2 Alert Thresholds

| Condition | Severity | Action |
|---|---|---|
| Any DeSO score changes > 20 points | Warning | Log, include in review |
| > 100 DeSOs change > 15 points | Error | Block auto-publish, require manual review |
| Mean national score shifts > 5 points | Error | Something systemic changed — investigate |
| Sentinel area score outside range | Error | Block publish |
| Standard deviation changes > 20% | Warning | Distribution shape changed — check normalization |

### 4.3 Indicator-Level Drift

Also detect drift at the indicator level — before it propagates to composite scores:

```php
// After ingestion, compare new raw_values to previous year
SELECT
    i.slug,
    COUNT(*) AS total_desos,
    COUNT(CASE WHEN ABS(new.raw_value - old.raw_value) / NULLIF(old.raw_value, 0) > 0.5 THEN 1 END) AS large_changes,
    AVG(new.raw_value) AS new_mean,
    AVG(old.raw_value) AS old_mean
FROM indicator_values new
JOIN indicator_values old
    ON old.deso_code = new.deso_code
    AND old.indicator_id = new.indicator_id
    AND old.year = new.year - 1
JOIN indicators i ON i.id = new.indicator_id
WHERE new.year = ?
GROUP BY i.slug;
```

If an indicator shows > 10% of DeSOs with > 50% value changes, flag it — either something real happened or the source changed methodology.

---

## Step 5: Data Freshness Tracking

### 5.1 The Problem

The user sees scores on the map but doesn't know how current the data is. Schools data might be from 2024, crime data from 2023, SCB income from 2022. This matters for trust and for the methodology page's accuracy claims.

### 5.2 Update the Indicators Table

Add freshness tracking:

```php
// Add to indicators table
$table->date('latest_data_date')->nullable();       // When the latest data was published by the source
$table->timestamp('last_ingested_at')->nullable();   // When we last successfully ingested
$table->timestamp('last_validated_at')->nullable();  // When validation last passed
$table->string('freshness_status', 20)->default('unknown');  // 'current', 'stale', 'outdated', 'unknown'
```

### 5.3 Freshness Rules

| Source | Expected update | 'stale' after | 'outdated' after |
|---|---|---|---|
| SCB demographics | Annually (Q1) | 15 months since last data | 24 months |
| Skolverket schools | Monthly (registry), Annually (stats) | 3 months (registry), 15 months (stats) | 6 / 24 months |
| BRÅ crime | Quarterly | 6 months | 12 months |
| Kronofogden debt | Annually | 15 months | 24 months |

### 5.4 Freshness Check Command

```bash
php artisan check:freshness
```

Runs daily. Updates `freshness_status` for all indicators. Generates alerts for stale/outdated sources.

### 5.5 Expose to Frontend

The sidebar (area detail view) should show:
```
Data sources
  Income data: Current (2024)
  School data: Current (2024/25)
  Crime data: Stale — awaiting 2024 Q4 update
  Financial distress: Current (2024)
```

---

## Step 6: Data Quality Dashboard (Admin)

### 6.1 Route

```php
Route::get('/admin/data-quality', [AdminDataQualityController::class, 'index'])->name('admin.data-quality');
```

### 6.2 Dashboard Layout

```
┌──────────────────────────────────────────────────────────┐
│  Data Quality Dashboard                                   │
├──────────────────────────────────────────────────────────┤
│                                                           │
│  Overall Health: ● HEALTHY (3 warnings)                   │
│                                                           │
│  ┌─────────────────────────────────────────────────────┐ │
│  │ Source Health                                        │ │
│  │                                                     │ │
│  │ SCB Demographics    ● Current   Last: 2025-01-15   │ │
│  │ Skolverket Schools  ● Current   Last: 2025-02-01   │ │
│  │ BRÅ Crime           ○ Stale     Last: 2024-09-20   │ │
│  │ Kronofogden Debt    ● Current   Last: 2025-01-10   │ │
│  └─────────────────────────────────────────────────────┘ │
│                                                           │
│  ┌─────────────────────────────────────────────────────┐ │
│  │ Latest Score Version: #42                            │ │
│  │ Status: Published                                    │ │
│  │ Computed: 2025-02-01 14:30                           │ │
│  │ DeSOs scored: 6,147 / 6,160                          │ │
│  │ Mean score: 51.3 (prev: 50.8)                        │ │
│  │ Sentinel checks: 8/8 passed                          │ │
│  │                                                     │ │
│  │ [View drift report] [Rollback to #41]                │ │
│  └─────────────────────────────────────────────────────┘ │
│                                                           │
│  ┌─────────────────────────────────────────────────────┐ │
│  │ Recent Validation Results                            │ │
│  │                                                     │ │
│  │ 2025-02-01 ingest:scb         ● 12 passed, 0 failed│ │
│  │ 2025-02-01 ingest:skolverket  ⚠ 10 passed, 2 warn  │ │
│  │ 2025-01-15 ingest:kronofogden ● 8 passed, 0 failed │ │
│  │                                                     │ │
│  │ [View all validations]                               │ │
│  └─────────────────────────────────────────────────────┘ │
│                                                           │
│  ┌─────────────────────────────────────────────────────┐ │
│  │ Sentinel Areas                                       │ │
│  │                                                     │ │
│  │ ✓ Danderyd centrum    Score: 84   (expected 75-95)  │ │
│  │ ✓ Lidingö inner       Score: 81   (expected 70-92)  │ │
│  │ ✓ Rinkeby             Score: 12   (expected 5-30)   │ │
│  │ ✓ Rosengård           Score: 18   (expected 5-30)   │ │
│  │ ✓ Angered             Score: 22   (expected 5-35)   │ │
│  │ ...                                                  │ │
│  └─────────────────────────────────────────────────────┘ │
│                                                           │
│  ┌─────────────────────────────────────────────────────┐ │
│  │ Ingestion History                                    │ │
│  │                                                     │ │
│  │ Date       Source      Records  Status   Duration   │ │
│  │ 2025-02-01 scb         6,147   ●        3m 22s     │ │
│  │ 2025-02-01 skolverket  9,842   ⚠        8m 15s     │ │
│  │ 2025-01-15 kronofogden 6,160   ●        1m 05s     │ │
│  │ ...                                                  │ │
│  └─────────────────────────────────────────────────────┘ │
│                                                           │
└──────────────────────────────────────────────────────────┘
```

### 6.3 Components

Use shadcn `Card`, `Table`, `Badge`, `Alert` components. Health indicators as colored dots (green/yellow/red). Score version card with publish/rollback actions. Expandable rows for validation detail.

---

## Step 7: Pipeline Orchestration

### 7.1 The Full Pipeline Command

Create a master command that runs the entire pipeline in the correct order with validation gates:

```bash
php artisan pipeline:run [--source=all] [--year=2024] [--auto-publish]
```

```php
class RunPipeline extends Command
{
    public function handle()
    {
        $year = $this->option('year') ?? now()->year - 1;

        // Stage 1: Ingest
        $this->info("=== Stage 1: Ingestion ===");
        $this->call('ingest:scb', ['--year' => $year]);
        $this->call('ingest:skolverket-schools');
        $this->call('ingest:skolverket-stats');
        // future: ingest:bra, ingest:kronofogden, etc.

        // Stage 2: Validate ingestions
        $this->info("=== Stage 2: Validation ===");
        $report = app(DataValidationService::class)->validateAll($year);
        if ($report->hasBlockingFailures()) {
            $this->error("Pipeline halted: blocking validation failures.");
            $this->error($report->summary());
            return Command::FAILURE;
        }

        // Stage 3: Aggregate & Normalize
        $this->info("=== Stage 3: Aggregate & Normalize ===");
        $this->call('aggregate:school-indicators', ['--academic-year' => $year]);
        $this->call('normalize:indicators', ['--year' => $year]);

        // Stage 4: Compute scores (creates new version in 'pending' state)
        $this->info("=== Stage 4: Compute Scores ===");
        $this->call('compute:scores', ['--year' => $year]);
        $version = ScoreVersion::latest()->first();

        // Stage 5: Sentinel checks
        $this->info("=== Stage 5: Sentinel Checks ===");
        $sentinelResult = $this->call('check:sentinels', ['--year' => $year]);
        if ($sentinelResult !== Command::SUCCESS) {
            $version->update(['status' => 'pending', 'sentinel_results' => 'FAILED']);
            $this->error("Sentinel checks failed. Scores NOT published. Review at /admin/data-quality");
            return Command::FAILURE;
        }
        $version->update(['status' => 'validated']);

        // Stage 6: Drift detection
        $this->info("=== Stage 6: Drift Analysis ===");
        $previousVersion = ScoreVersion::where('status', 'published')
            ->where('year', $year)
            ->latest()
            ->first();
        if ($previousVersion) {
            $drift = app(ScoreDriftDetector::class)->detect($version, $previousVersion);
            $this->info("Mean drift: {$drift->meanDrift} | Max drift: {$drift->maxDrift}");
            if ($drift->hasSystemicShift()) {
                $this->warn("Large systemic shift detected. Manual review recommended.");
                if (!$this->option('auto-publish')) {
                    return Command::SUCCESS; // Scores validated but not published
                }
            }
        }

        // Stage 7: Publish (if auto-publish or first run)
        if ($this->option('auto-publish') || !$previousVersion) {
            $this->call('scores:publish', ['--version' => $version->id]);
            $this->info("✓ Scores published as version #{$version->id}");
        } else {
            $this->info("Scores validated but not published. Run: php artisan scores:publish --version={$version->id}");
        }

        // Stage 8: H3 projection + smoothing (if H3 is implemented)
        // $this->call('project:scores-to-h3', ['--year' => $year]);
        // $this->call('smooth:h3-scores', ['--year' => $year]);

        // Stage 9: Freshness check
        $this->call('check:freshness');

        $this->info("=== Pipeline complete ===");
        return Command::SUCCESS;
    }
}
```

### 7.2 Scheduled Run

```php
// In schedule
$schedule->command('pipeline:run --auto-publish --year=' . (now()->year - 1))
    ->monthlyOn(1, '03:00')
    ->emailOutputOnFailure('admin@example.com');
```

---

## Step 8: Alerting

### 8.1 Keep It Simple

For v1, alerting means: write to Laravel's log, send a notification via Laravel's notification system, and show it on the admin dashboard.

Don't build a full alerting system. Use Laravel's built-in notification channels:

```php
class DataQualityAlert extends Notification
{
    public function __construct(
        public string $severity,  // 'error', 'warning', 'info'
        public string $source,
        public string $message,
        public ?array $details = null,
    ) {}

    public function via($notifiable): array
    {
        return $this->severity === 'error'
            ? ['database', 'mail']  // Errors get emailed
            : ['database'];          // Warnings just logged
    }

    // ...
}
```

### 8.2 Alert Triggers

| Event | Severity | Alert |
|---|---|---|
| Validation rule with severity 'error' fails | Error | Email + dashboard |
| Sentinel check fails | Error | Email + dashboard |
| Ingestion command fails entirely | Error | Email + dashboard |
| Validation warning | Warning | Dashboard only |
| Score drift > 20 points for any DeSO | Warning | Dashboard only |
| Data source becomes 'stale' | Warning | Dashboard only |
| Data source becomes 'outdated' | Error | Email + dashboard |
| Ingestion completes successfully | Info | Dashboard only |

### 8.3 Admin Notification Bell

On the admin navbar, show an unread notification count badge. Clicking it shows recent alerts. Standard Laravel notifications stored in the database.

---

## Step 9: Verification

### 9.1 Test the Validation Engine

```bash
# Run ingestion with validation
php artisan ingest:scb --year=2024

# Check validation results
SELECT vr.status, vr.message, vr.affected_count, vrl.name
FROM validation_results vr
JOIN validation_rules vrl ON vrl.id = vr.validation_rule_id
WHERE vr.ingestion_log_id = (SELECT MAX(id) FROM ingestion_logs)
ORDER BY vr.status;
```

### 9.2 Test Sentinel Checks

```bash
php artisan check:sentinels --year=2024
# Should pass if the data is reasonable
```

### 9.3 Test Rollback

```bash
# Compute scores (creates version N)
php artisan compute:scores --year=2024

# Publish
php artisan scores:publish --version=N

# Verify API serves version N
curl /api/deso/scores | jq '.["0162A0010"].score'

# Now artificially corrupt data and recompute (creates version N+1)
# Then rollback:
php artisan scores:rollback --to-version=N --reason="Testing rollback"

# Verify API still serves version N
curl /api/deso/scores | jq '.["0162A0010"].score'
```

### 9.4 Visual Checklist

- [ ] Ingestion commands output validation results (passed/warned/failed)
- [ ] Blocking validation failures prevent score computation
- [ ] Sentinel check runs after score computation
- [ ] Admin data quality dashboard shows all sections (health, versions, validations, sentinels, history)
- [ ] Score versions are tracked — recomputing creates a new version, not overwriting
- [ ] Publish/rollback commands work correctly
- [ ] API only serves published score versions
- [ ] Data freshness indicators show in sidebar (current/stale/outdated)
- [ ] Admin notification badge shows alerts
- [ ] `pipeline:run` command executes the full pipeline in correct order with gates
- [ ] A deliberate bad ingestion (e.g., all zeros) is caught by validation before reaching scoring

---

## Notes for the Agent

### This Is Infrastructure, Not Features

None of this is visible to end users except the data freshness indicators in the sidebar. Everything else is backend/admin. But it's the foundation that makes the product trustworthy at scale.

### Don't Over-Engineer Alerting

For v1: database notifications + email for errors. No Slack integration, no PagerDuty, no custom webhook system. If those are needed later, Laravel's notification system makes it trivial to add channels.

### Sentinel Areas Need Actual DeSO Codes

The task lists kommun names. The agent needs to query the database to find specific DeSO codes within those kommuner. Pick DeSOs that are clearly residential and representative — avoid industrial zones or tiny DeSOs that might have weird data.

### The Score Version System Changes the API Contract

Currently the API probably queries `composite_scores` directly. After this task, it queries through `score_versions` to find the published version. This is a breaking change in the query logic (not the API shape). Test the API carefully.

### What NOT to Do

- Don't build a custom alerting dashboard — use Laravel notifications
- Don't add authentication to the admin dashboard (that's a separate task)
- Don't validate at the individual-record level for every single DeSO (too slow) — aggregate checks are usually sufficient
- Don't make validation synchronous with the API — it runs during ingestion, not on every request
- Don't block scoring on warnings — only on errors with `blocks_scoring = true`

### What to Prioritize

1. Validation rules + service (catches problems at ingestion time)
2. Sentinel areas (catches problems at scoring time)
3. Score versioning + rollback (enables recovery)
4. Data quality dashboard (makes everything visible)
5. Pipeline orchestration (ties it all together)
6. Anomaly detection + alerting (polish)

### Future Enhancements (Not This Task)

- Automated data source monitoring (detect when SCB publishes new tables)
- A/B testing for weight changes (compute two versions, compare drift)
- Backtesting: when a weight changes, how would historical scores have looked?
- User-facing data quality badge on the map ("Data confidence: High / Medium / Low" per DeSO)
- Integration with external monitoring (Sentry, Datadog, etc.)

### Update CLAUDE.md

Add: validation rule patterns, sentinel area DeSO codes, score version workflow, any gotchas from the rollback implementation.