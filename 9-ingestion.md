# TASK: Admin Ingestion Dashboard

## Context

Right now, monitoring the data pipeline means SSH-ing into the Docker container, running artisan commands manually, and querying the database to see if things worked. This is unsustainable. We need a proper admin UI that shows:

- What data sources exist and their current state
- When each source was last ingested, and whether it succeeded
- How many records were processed, created, updated, failed
- The ability to re-run any ingestion from the browser
- Detailed logs for each ingestion run
- At-a-glance health: is everything current, or is something stale/broken?

This is an internal tool — function over beauty. But it needs to be fast, informative, and reliable. The design system task defines the admin visual language (gray background, white cards, shadcn components).

## Goals

1. Admin page at `/admin/pipeline` showing all data sources with status
2. Per-source detail view with ingestion history and logs
3. One-click re-run for any ingestion command (with progress feedback)
4. Pipeline health dashboard — green/yellow/red per source
5. Full pipeline run button (runs everything in sequence with gates)

---

## Step 1: Data Model

### 1.1 Extend Ingestion Logs

The `ingestion_logs` table already exists. Extend it to be more useful:

```php
// Migration: add columns to ingestion_logs
Schema::table('ingestion_logs', function (Blueprint $table) {
    $table->string('trigger', 20)->default('manual');     // 'manual', 'scheduled', 'pipeline'
    $table->string('triggered_by')->nullable();            // 'admin:user@example.com', 'scheduler', 'pipeline:run'
    $table->integer('records_failed')->default(0);
    $table->integer('records_skipped')->default(0);
    $table->text('summary')->nullable();                   // Human-readable summary of what happened
    $table->json('warnings')->nullable();                  // Non-fatal issues ["12 schools missing coordinates", ...]
    $table->json('stats')->nullable();                     // Arbitrary stats {"grundskola": 4748, "gymnasie": 1313, ...}
    $table->integer('duration_seconds')->nullable();
    $table->decimal('memory_peak_mb', 8, 2)->nullable();
});
```

### 1.2 Data Sources Registry

Create a config-driven registry of all data sources and their commands:

```php
// config/pipeline.php
return [
    'sources' => [
        'scb' => [
            'name' => 'SCB Demographics',
            'description' => 'Population, income, employment, education statistics at DeSO level',
            'commands' => [
                'ingest' => 'ingest:scb',
                'normalize' => 'normalize:indicators',
            ],
            'expected_frequency' => 'annually',       // 'daily', 'monthly', 'quarterly', 'annually'
            'stale_after_days' => 400,                // If last successful run is older than this, show warning
            'critical' => true,                        // If this source is down, the whole pipeline is degraded
            'indicators' => ['median_income', 'low_economic_standard_pct', 'employment_rate',
                           'education_post_secondary_pct', 'education_below_secondary_pct',
                           'foreign_background_pct', 'population', 'rental_tenure_pct'],
        ],
        'skolverket_schools' => [
            'name' => 'Skolverket School Registry',
            'description' => 'School locations, types, operators from Skolenhetsregistret',
            'commands' => [
                'ingest' => 'ingest:skolverket-schools',
                'geocode' => 'geocode:schools',
            ],
            'expected_frequency' => 'monthly',
            'stale_after_days' => 45,
            'critical' => true,
            'indicators' => [],
        ],
        'skolverket_stats' => [
            'name' => 'Skolverket Statistics',
            'description' => 'School performance: meritvärde, goal achievement, teacher certification',
            'commands' => [
                'ingest' => 'ingest:skolverket-stats',
                'aggregate' => 'aggregate:school-indicators',
            ],
            'expected_frequency' => 'annually',
            'stale_after_days' => 400,
            'critical' => true,
            'indicators' => ['school_merit_value_avg', 'school_goal_achievement_avg', 'school_teacher_certification_avg'],
        ],
        'scoring' => [
            'name' => 'Score Computation',
            'description' => 'Normalize indicators and compute composite scores',
            'commands' => [
                'normalize' => 'normalize:indicators',
                'score' => 'compute:scores',
                'trends' => 'compute:trends',
            ],
            'expected_frequency' => 'on_demand',
            'stale_after_days' => null,
            'critical' => true,
            'indicators' => [],
        ],
        // Future sources:
        // 'bra' => [...],
        // 'kronofogden' => [...],
        // 'pois' => [...],
    ],

    'pipeline_order' => [
        'scb',
        'skolverket_schools',
        'skolverket_stats',
        'scoring',
    ],
];
```

This config drives the entire dashboard. Adding a new data source means adding an entry here — the UI renders automatically.

---

## Step 2: Backend — Pipeline Controller

### 2.1 Routes

```php
Route::prefix('admin/pipeline')->group(function () {
    Route::get('/', [AdminPipelineController::class, 'index'])->name('admin.pipeline');
    Route::get('/{source}', [AdminPipelineController::class, 'show'])->name('admin.pipeline.show');
    Route::post('/{source}/run', [AdminPipelineController::class, 'run'])->name('admin.pipeline.run');
    Route::post('/run-all', [AdminPipelineController::class, 'runAll'])->name('admin.pipeline.run-all');
    Route::get('/logs/{log}', [AdminPipelineController::class, 'log'])->name('admin.pipeline.log');
});
```

### 2.2 Controller

```php
class AdminPipelineController extends Controller
{
    public function index()
    {
        $sources = collect(config('pipeline.sources'))->map(function ($config, $key) {
            $lastRun = IngestionLog::where('source', $key)
                ->latest('started_at')
                ->first();

            $lastSuccess = IngestionLog::where('source', $key)
                ->where('status', 'completed')
                ->latest('completed_at')
                ->first();

            $runningNow = IngestionLog::where('source', $key)
                ->where('status', 'running')
                ->exists();

            return [
                'key' => $key,
                'name' => $config['name'],
                'description' => $config['description'],
                'expected_frequency' => $config['expected_frequency'],
                'critical' => $config['critical'],
                'health' => $this->computeHealth($config, $lastSuccess),
                'last_run' => $lastRun ? [
                    'id' => $lastRun->id,
                    'status' => $lastRun->status,
                    'started_at' => $lastRun->started_at,
                    'completed_at' => $lastRun->completed_at,
                    'duration_seconds' => $lastRun->duration_seconds,
                    'records_processed' => $lastRun->records_processed,
                    'records_created' => $lastRun->records_created,
                    'records_updated' => $lastRun->records_updated,
                    'records_failed' => $lastRun->records_failed,
                    'summary' => $lastRun->summary,
                    'has_warnings' => !empty($lastRun->warnings),
                    'has_errors' => $lastRun->status === 'failed',
                ] : null,
                'last_success_at' => $lastSuccess?->completed_at,
                'running' => $runningNow,
                'indicator_count' => count($config['indicators'] ?? []),
                'commands' => array_keys($config['commands']),
            ];
        });

        // Overall health
        $overallHealth = $sources->every(fn ($s) => $s['health'] === 'healthy')
            ? 'healthy'
            : ($sources->contains(fn ($s) => $s['health'] === 'critical') ? 'critical' : 'warning');

        // Quick stats
        $stats = [
            'total_ingestion_runs' => IngestionLog::count(),
            'runs_last_7_days' => IngestionLog::where('started_at', '>=', now()->subDays(7))->count(),
            'failed_last_7_days' => IngestionLog::where('started_at', '>=', now()->subDays(7))
                ->where('status', 'failed')->count(),
            'total_indicators' => \App\Models\Indicator::where('is_active', true)->count(),
            'total_desos_with_scores' => \App\Models\CompositeScore::distinct('deso_code')->count('deso_code'),
            'total_schools' => \App\Models\School::where('status', 'active')->count(),
        ];

        return Inertia::render('Admin/Pipeline', [
            'sources' => $sources->values(),
            'overallHealth' => $overallHealth,
            'stats' => $stats,
            'pipelineOrder' => config('pipeline.pipeline_order'),
        ]);
    }

    private function computeHealth(array $config, ?IngestionLog $lastSuccess): string
    {
        if (!$lastSuccess) return 'unknown';
        if ($config['stale_after_days'] === null) return 'healthy';

        $daysSinceSuccess = $lastSuccess->completed_at->diffInDays(now());

        if ($daysSinceSuccess > $config['stale_after_days']) return 'critical';
        if ($daysSinceSuccess > $config['stale_after_days'] * 0.8) return 'warning';
        return 'healthy';
    }

    public function show(string $source)
    {
        $config = config("pipeline.sources.{$source}");
        abort_unless($config, 404);

        $logs = IngestionLog::where('source', $source)
            ->latest('started_at')
            ->limit(50)
            ->get();

        // Per-indicator coverage for this source
        $indicators = \App\Models\Indicator::where('source', $source)
            ->orWhereIn('slug', $config['indicators'] ?? [])
            ->get()
            ->map(function ($indicator) {
                $latestYear = \App\Models\IndicatorValue::where('indicator_id', $indicator->id)
                    ->max('year');
                $coverage = $latestYear
                    ? \App\Models\IndicatorValue::where('indicator_id', $indicator->id)
                        ->where('year', $latestYear)
                        ->whereNotNull('raw_value')
                        ->count()
                    : 0;

                return [
                    'slug' => $indicator->slug,
                    'name' => $indicator->name,
                    'latest_year' => $latestYear,
                    'deso_coverage' => $coverage,
                    'coverage_pct' => $coverage > 0 ? round($coverage / 6160 * 100, 1) : 0,
                ];
            });

        return Inertia::render('Admin/PipelineSource', [
            'source' => array_merge($config, ['key' => $source]),
            'logs' => $logs,
            'indicators' => $indicators,
        ]);
    }

    public function run(Request $request, string $source)
    {
        $config = config("pipeline.sources.{$source}");
        abort_unless($config, 404);

        $command = $request->input('command', 'ingest');
        $artisanCommand = $config['commands'][$command] ?? null;
        abort_unless($artisanCommand, 400, "Unknown command: {$command}");

        $options = $request->input('options', []);

        // Dispatch as a queued job so the HTTP request returns immediately
        dispatch(new \App\Jobs\RunIngestionCommand(
            source: $source,
            command: $artisanCommand,
            options: $options,
            triggeredBy: 'admin',  // TODO: replace with auth user when auth is added
        ));

        return back()->with('message', "Started {$config['name']} — {$command}. Refresh to see progress.");
    }

    public function runAll()
    {
        dispatch(new \App\Jobs\RunFullPipeline(triggeredBy: 'admin'));

        return back()->with('message', 'Full pipeline started. This may take several minutes.');
    }

    public function log(IngestionLog $log)
    {
        return response()->json($log);
    }
}
```

### 2.3 Job: RunIngestionCommand

```php
// app/Jobs/RunIngestionCommand.php
class RunIngestionCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $source,
        public string $command,
        public array $options = [],
        public string $triggeredBy = 'manual',
    ) {}

    public function handle(): void
    {
        $log = IngestionLog::create([
            'source' => $this->source,
            'command' => $this->command,
            'status' => 'running',
            'trigger' => 'manual',
            'triggered_by' => $this->triggeredBy,
            'started_at' => now(),
            'metadata' => ['options' => $this->options],
        ]);

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $exitCode = Artisan::call($this->command, $this->options);
            $output = Artisan::output();

            $log->update([
                'status' => $exitCode === 0 ? 'completed' : 'failed',
                'completed_at' => now(),
                'duration_seconds' => (int)(microtime(true) - $startTime),
                'memory_peak_mb' => round((memory_get_peak_usage(true) - $startMemory) / 1024 / 1024, 2),
                'summary' => Str::limit($output, 2000),
                'error_message' => $exitCode !== 0 ? $output : null,
            ]);
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'completed_at' => now(),
                'duration_seconds' => (int)(microtime(true) - $startTime),
                'error_message' => $e->getMessage(),
                'summary' => "Exception: {$e->getMessage()}",
            ]);

            throw $e;
        }
    }
}
```

### 2.4 Trait: LogsIngestion

Every artisan ingestion command should use this trait to standardize logging:

```php
// app/Console/Concerns/LogsIngestion.php
trait LogsIngestion
{
    protected ?IngestionLog $ingestionLog = null;
    protected int $processed = 0;
    protected int $created = 0;
    protected int $updated = 0;
    protected int $failed = 0;
    protected int $skipped = 0;
    protected array $warnings = [];
    protected array $stats = [];

    protected function startIngestionLog(string $source, string $command): void
    {
        $this->ingestionLog = IngestionLog::create([
            'source' => $source,
            'command' => $command,
            'status' => 'running',
            'trigger' => app()->runningInConsole() ? 'cli' : 'queue',
            'started_at' => now(),
        ]);
    }

    protected function completeIngestionLog(?string $summary = null): void
    {
        $this->ingestionLog?->update([
            'status' => 'completed',
            'completed_at' => now(),
            'records_processed' => $this->processed,
            'records_created' => $this->created,
            'records_updated' => $this->updated,
            'records_failed' => $this->failed,
            'records_skipped' => $this->skipped,
            'warnings' => $this->warnings ?: null,
            'stats' => $this->stats ?: null,
            'summary' => $summary ?? $this->buildSummary(),
            'duration_seconds' => $this->ingestionLog->started_at->diffInSeconds(now()),
        ]);
    }

    protected function failIngestionLog(string $error): void
    {
        $this->ingestionLog?->update([
            'status' => 'failed',
            'completed_at' => now(),
            'records_processed' => $this->processed,
            'error_message' => $error,
            'duration_seconds' => $this->ingestionLog->started_at->diffInSeconds(now()),
        ]);
    }

    protected function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
        $this->warn($warning);
    }

    protected function addStat(string $key, mixed $value): void
    {
        $this->stats[$key] = $value;
    }

    private function buildSummary(): string
    {
        $parts = ["Processed: {$this->processed}"];
        if ($this->created > 0) $parts[] = "Created: {$this->created}";
        if ($this->updated > 0) $parts[] = "Updated: {$this->updated}";
        if ($this->failed > 0) $parts[] = "Failed: {$this->failed}";
        if ($this->skipped > 0) $parts[] = "Skipped: {$this->skipped}";
        if (count($this->warnings) > 0) $parts[] = "Warnings: " . count($this->warnings);
        return implode(' | ', $parts);
    }
}
```

Every ingestion command uses it:

```php
class IngestScbData extends Command
{
    use LogsIngestion;

    public function handle(): int
    {
        $this->startIngestionLog('scb', 'ingest:scb');

        try {
            // ... existing logic, but increment $this->processed, $this->created, etc.
            // ... $this->addWarning("12 DeSO codes not found in mapping table");
            // ... $this->addStat('indicators_fetched', 8);

            $this->completeIngestionLog();
            return 0;
        } catch (\Throwable $e) {
            $this->failIngestionLog($e->getMessage());
            return 1;
        }
    }
}
```

---

## Step 3: Frontend — Pipeline Dashboard

### 3.1 Pipeline Overview Page (`/admin/pipeline`)

```
┌─────────────────────────────────────────────────────────────────┐
│  Admin > Pipeline                                     [Run All] │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐       │
│  │ Sources  │  │ Runs     │  │ Failed   │  │ DeSOs    │       │
│  │    4     │  │ 23 (7d)  │  │ 1 (7d)   │  │ 6,104    │       │
│  └──────────┘  └──────────┘  └──────────┘  └──────────┘       │
│                                                                 │
│  Source Health                                                  │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ ● SCB Demographics          Healthy   Last: 3 days ago  │   │
│  │   8 indicators · 6,104 DeSOs covered                    │   │
│  │   Last run: completed · 6,160 processed · 47s           │   │
│  │                                    [View] [Run ▾]       │   │
│  ├─────────────────────────────────────────────────────────┤   │
│  │ ● Skolverket Schools         Warning   Last: 38 days    │   │
│  │   10,247 active schools · 9,721 with DeSO              │   │
│  │   Last run: completed · 10,612 processed · 4m 12s       │   │
│  │                                    [View] [Run ▾]       │   │
│  ├─────────────────────────────────────────────────────────┤   │
│  │ ● Skolverket Statistics      Healthy   Last: 5 days ago │   │
│  │   3 indicators · 2,340 DeSOs covered                    │   │
│  │   Last run: completed · 4,748 processed · 12m 33s       │   │
│  │                                    [View] [Run ▾]       │   │
│  ├─────────────────────────────────────────────────────────┤   │
│  │ ● Score Computation          Healthy   Last: 5 days ago │   │
│  │   11 active indicators · 6,104 DeSOs scored             │   │
│  │   Last run: completed · 6,160 processed · 8s            │   │
│  │                                    [View] [Run ▾]       │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  Recent Activity                                                │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ Feb 5 14:23  compute:scores      ✓ completed    8s     │   │
│  │ Feb 5 14:22  normalize:indicators ✓ completed   12s    │   │
│  │ Feb 5 14:10  ingest:scb          ✓ completed    47s    │   │
│  │ Feb 3 09:00  ingest:skolverket-  ✗ failed       2m     │   │
│  │              schools             API timeout            │   │
│  │ Feb 1 11:30  ingest:skolverket-  ✓ completed    4m 12s │   │
│  │              schools                                    │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 3.2 Health Status Indicators

| Status | Dot color | Meaning |
|---|---|---|
| Healthy | Green (`bg-emerald-500`) | Last successful run within expected window |
| Warning | Yellow (`bg-amber-500`) | Approaching staleness (>80% of stale_after_days) |
| Critical | Red (`bg-red-500`) | Last success is past stale_after_days, or last run failed |
| Unknown | Gray (`bg-slate-400`) | Never run, no data |
| Running | Blue pulse (`bg-blue-500 animate-pulse`) | Currently executing |

### 3.3 Run Button Dropdown

Each source has a dropdown button with its available commands:

```
[Run ▾]
├── Ingest          (ingest:scb)
├── Normalize       (normalize:indicators)
└── Run with options...
```

"Run with options" opens a dialog where you can specify flags:

```
┌────────────────────────────────────┐
│ Run: ingest:scb                    │
│                                    │
│ Year:    [2024    ▾]              │
│ Indicator: [All     ▾]            │
│                                    │
│        [Cancel]  [Run]             │
└────────────────────────────────────┘
```

For now, keep options simple — year is the main one. The dialog fields are derived from the command's signature if possible, or hardcoded per source in the config.

### 3.4 Source Detail Page (`/admin/pipeline/{source}`)

Clicking "View" on a source opens its detail page:

```
┌──────────────────────────────────────────────────────────────┐
│  Admin > Pipeline > SCB Demographics               [Run ▾]  │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  Status: ● Healthy                                           │
│  Last success: Feb 5, 2025 14:10                             │
│  Expected frequency: Annually                                │
│  Commands: ingest:scb, normalize:indicators                  │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │ Indicator Coverage                                    │   │
│  │                                                       │   │
│  │ median_income         ████████████████████  99.1%     │   │
│  │ employment_rate       ████████████████████  98.7%     │   │
│  │ education_post_sec    ████████████████████  98.7%     │   │
│  │ low_econ_standard     ███████████████████░  95.2%     │   │
│  │ foreign_background    ████████████████████  99.1%     │   │
│  │ population            ████████████████████  99.9%     │   │
│  │ rental_tenure_pct     █████████████████░░░  87.3%     │   │
│  │ education_below_sec   ████████████████████  98.7%     │   │
│  │                                                       │   │
│  │ Latest year: 2023  ·  6,104 of 6,160 DeSOs           │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
│  Ingestion History                                           │
│  ┌──────────────────────────────────────────────────────┐   │
│  │ Date         Command        Status   Records  Time   │   │
│  │ Feb 5 14:10  ingest:scb     ✓        6,160    47s   │   │
│  │ Feb 5 14:22  normalize:ind  ✓        49,280   12s   │   │
│  │ Jan 15 09:00 ingest:scb     ✓        6,160    52s   │   │
│  │ Jan 15 09:01 normalize:ind  ✓        49,280   11s   │   │
│  │ Dec 20 11:00 ingest:scb     ✗        3,200    23s   │   │
│  │              └─ API timeout after 3200 records        │   │
│  │ Dec 19 15:00 ingest:scb     ✓        6,160    49s   │   │
│  │                                                       │   │
│  │ Showing 50 most recent · Total: 23 runs               │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

### 3.5 Log Detail Modal

Clicking a row in the history table opens a modal/drawer with the full log:

```
┌────────────────────────────────────────────────────────────┐
│ Ingestion Log #47                                    [×]   │
├────────────────────────────────────────────────────────────┤
│                                                            │
│ Source:    SCB Demographics                                │
│ Command:  ingest:scb --year=2023                          │
│ Status:   ✓ Completed                                     │
│ Trigger:  Manual (admin)                                  │
│                                                            │
│ Started:  Feb 5, 2025 14:10:03                            │
│ Finished: Feb 5, 2025 14:10:50                            │
│ Duration: 47 seconds                                       │
│ Memory:   84.3 MB peak                                     │
│                                                            │
│ Records:                                                   │
│   Processed: 6,160                                         │
│   Created:   0                                             │
│   Updated:   6,160                                         │
│   Failed:    0                                             │
│   Skipped:   0                                             │
│                                                            │
│ Stats:                                                     │
│   indicators_fetched: 8                                    │
│   api_requests: 8                                          │
│   deso_codes_matched: 6,104                                │
│   deso_codes_unmatched: 56                                 │
│                                                            │
│ Warnings (2):                                              │
│   ⚠ 56 DeSO codes in API response not found in our DB    │
│   ⚠ employment_rate: 12 null values in API response      │
│                                                            │
│ Summary:                                                   │
│   Processed: 6,160 | Updated: 6,160 | Warnings: 2         │
│                                                            │
│ Output:                                                    │
│ ┌────────────────────────────────────────────────────┐    │
│ │ Fetching median_income for year 2023...            │    │
│ │   → 6,160 values received                          │    │
│ │ Fetching employment_rate for year 2023...           │    │
│ │   → 6,148 values received (12 null)                │    │
│ │ ...                                                 │    │
│ │ All indicators fetched. Upserting to database...    │    │
│ │ Done. 6,160 records updated.                        │    │
│ └────────────────────────────────────────────────────┘    │
│                                                            │
└────────────────────────────────────────────────────────────┘
```

The output section shows the artisan command's console output (captured via `Artisan::output()`). Use a monospace font and a scrollable container with a max height.

---

## Step 4: Run All Pipeline Button

### 4.1 Full Pipeline Job

```php
// app/Jobs/RunFullPipeline.php
class RunFullPipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;  // 1 hour max

    public function __construct(
        public string $triggeredBy = 'manual',
        public array $options = [],
    ) {}

    public function handle(): void
    {
        $order = config('pipeline.pipeline_order');
        $year = $this->options['year'] ?? now()->year - 1;

        $pipelineLog = IngestionLog::create([
            'source' => 'pipeline',
            'command' => 'pipeline:run',
            'status' => 'running',
            'trigger' => 'manual',
            'triggered_by' => $this->triggeredBy,
            'started_at' => now(),
        ]);

        $results = [];

        try {
            foreach ($order as $sourceKey) {
                $config = config("pipeline.sources.{$sourceKey}");
                if (!$config) continue;

                foreach ($config['commands'] as $name => $command) {
                    $stepStart = microtime(true);

                    $exitCode = Artisan::call($command, ['--year' => $year]);
                    $output = Artisan::output();

                    $results[] = [
                        'source' => $sourceKey,
                        'command' => $command,
                        'exit_code' => $exitCode,
                        'duration' => round(microtime(true) - $stepStart, 1),
                        'output_preview' => Str::limit($output, 500),
                    ];

                    if ($exitCode !== 0) {
                        // Log failure but continue (don't halt entire pipeline)
                        Log::warning("Pipeline step failed: {$command}", [
                            'exit_code' => $exitCode,
                            'output' => $output,
                        ]);
                    }
                }
            }

            $pipelineLog->update([
                'status' => collect($results)->every(fn ($r) => $r['exit_code'] === 0)
                    ? 'completed' : 'completed_with_errors',
                'completed_at' => now(),
                'duration_seconds' => $pipelineLog->started_at->diffInSeconds(now()),
                'stats' => $results,
                'summary' => sprintf(
                    '%d steps completed, %d failed',
                    collect($results)->where('exit_code', 0)->count(),
                    collect($results)->where('exit_code', '!=', 0)->count(),
                ),
            ]);
        } catch (\Throwable $e) {
            $pipelineLog->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
                'stats' => $results,
            ]);

            throw $e;
        }
    }
}
```

### 4.2 Run All UI

The "Run All" button at the top of the pipeline page opens a confirmation dialog:

```
┌────────────────────────────────────────┐
│ Run Full Pipeline                      │
│                                        │
│ This will run all data sources in      │
│ sequence:                              │
│                                        │
│   1. SCB Demographics (ingest)         │
│   2. Skolverket Schools (ingest)       │
│   3. Skolverket Statistics (ingest +   │
│      aggregate)                        │
│   4. Score Computation (normalize +    │
│      score + trends)                   │
│                                        │
│ Year: [2024 ▾]                        │
│                                        │
│ Estimated time: 15-20 minutes          │
│                                        │
│          [Cancel]  [Run Pipeline]      │
└────────────────────────────────────────┘
```

### 4.3 Progress Feedback

While a job is running, the dashboard should show it. Use polling (simple) or broadcasting (fancy):

**Simple approach (polling):**
The frontend polls `/admin/pipeline` every 5 seconds while any source shows `running: true`. The page auto-refreshes the relevant source card to show the latest status.

```tsx
useEffect(() => {
    if (sources.some(s => s.running)) {
        const interval = setInterval(() => {
            router.reload({ only: ['sources'] });
        }, 5000);
        return () => clearInterval(interval);
    }
}, [sources]);
```

**Running state UI:**
When a source is running, show a pulsing blue dot and a spinner:

```
│ ◉ SCB Demographics         Running...  Started 23s ago     │
│   ⟳ ingest:scb in progress                                │
│                                    [View] [Cancel]          │
```

---

## Step 5: Retrofit Existing Commands

### 5.1 Commands That Need the LogsIngestion Trait

Every existing ingestion/processing command should be updated to use the `LogsIngestion` trait and produce structured log output:

- `ingest:scb` — already has some logging, add trait
- `ingest:skolverket-schools` — add trait
- `ingest:skolverket-stats` — add trait
- `aggregate:school-indicators` — add trait
- `normalize:indicators` — add trait
- `compute:scores` — add trait
- `compute:trends` — add trait (when implemented)
- `geocode:schools` — add trait (when implemented)

### 5.2 What Each Command Should Log

At minimum, every command must:

1. Call `$this->startIngestionLog(source, command)` at the beginning
2. Increment `$this->processed`, `$this->created`, `$this->updated`, `$this->failed` during execution
3. Call `$this->addWarning()` for non-fatal issues
4. Call `$this->addStat()` for interesting metrics
5. Call `$this->completeIngestionLog()` on success or `$this->failIngestionLog()` on failure

Example for `ingest:scb`:

```php
$this->startIngestionLog('scb', 'ingest:scb');

foreach ($indicators as $indicator) {
    $values = $this->scbService->fetchIndicator($indicator, $year);
    $this->addStat("api_values_{$indicator->slug}", count($values));

    foreach ($values as $desoCode => $value) {
        $this->processed++;

        if (!$this->desoExists($desoCode)) {
            $this->skipped++;
            continue;
        }

        $result = IndicatorValue::updateOrCreate(...);
        $result->wasRecentlyCreated ? $this->created++ : $this->updated++;
    }
}

$this->completeIngestionLog();
```

---

## Step 6: Navigation Update

### 6.1 Add Pipeline to Admin Nav

The admin section currently has `/admin/indicators`. Add pipeline:

```
Admin ▾
├── Indicators      (/admin/indicators)
├── Pipeline        (/admin/pipeline)      ← NEW
└── [future: Data Quality, Users, etc.]
```

### 6.2 Pipeline Link in Navbar

On the main app navbar, add a subtle status indicator that links to the pipeline dashboard. A small dot showing overall health:

```
Map   Methodology          ● Pipeline   Admin ▾
                           ^ green dot if healthy, yellow if warning, red if critical
```

This gives you at-a-glance pipeline health without entering the admin area.

---

## Step 7: Verification

### 7.1 Functional Checklist

- [ ] `/admin/pipeline` loads and shows all configured sources
- [ ] Health dots are correct (green for recently-run, yellow for approaching staleness, gray for never-run)
- [ ] Clicking "Run" on a source dispatches the job and returns immediately
- [ ] The dashboard shows "Running..." state with polling updates
- [ ] After job completes, the dashboard refreshes to show success/failure
- [ ] Clicking "View" on a source shows ingestion history with 50 most recent runs
- [ ] Clicking a log row shows full detail modal with output, stats, warnings
- [ ] "Run All" dispatches the full pipeline in sequence
- [ ] Failed runs show red status with error message visible
- [ ] Stats cards at the top show correct totals
- [ ] Existing commands produce structured logs via the LogsIngestion trait
- [ ] Quick stats (total schools, DeSOs with scores, etc.) are accurate
- [ ] Source detail page shows per-indicator coverage bars

### 7.2 Test Scenarios

1. **Run a single source** — click Run on SCB, verify it appears as "running", wait for completion, verify log appears
2. **Run all** — click Run All, verify each source runs in sequence, final log shows all steps
3. **Simulate failure** — temporarily break an API URL, run the source, verify it shows failed with error message
4. **Check staleness** — if a source hasn't run in a while, verify the health dot turns yellow/red
5. **Verify log detail** — click into a completed log, verify records counts, warnings, stats, and output are all present

---

## Notes for the Agent

### Queue Must Be Running

The "Run" buttons dispatch jobs to the Laravel queue. The queue worker must be running in Docker:

```bash
php artisan queue:work --tries=1 --timeout=3600
```

Add this to the Docker setup if not already present. If the queue isn't running, clicking "Run" will appear to do nothing (the job sits in the queue forever).

If the queue setup is complex, an alternative is to run commands synchronously via `Artisan::call()` in the controller (not dispatched to queue). This blocks the HTTP request but works without queue infrastructure. Use this as a fallback if queue setup is problematic — the ingestion commands run in seconds to minutes, not hours.

### Polling vs WebSockets

Use polling (5-second interval) for v1. It's simple and reliable. WebSockets/broadcasting (via Laravel Echo + Pusher/Soketi) is better UX but adds infrastructure complexity. Not worth it for an admin dashboard with one user.

### Don't Over-Engineer the Options Dialog

The "Run with options" dialog doesn't need to dynamically parse artisan command signatures. Just hardcode the common options per source:

- SCB: `--year` (select), `--indicator` (select)
- Skolverket: `--force` (checkbox), `--academic-year` (input)
- Scoring: `--year` (select)

### Config-Driven Is Key

All source definitions live in `config/pipeline.php`. When BRÅ, Kronofogden, or POI sources are added later, you add an entry to the config and the dashboard picks them up automatically. No new pages, no new controllers, no new components.

### What NOT to Do

- Don't build a real-time log streaming UI (tail -f style) — too complex for v1
- Don't add authentication yet — it's the same admin area that already has no auth
- Don't store command output in the database beyond the summary — it can be huge
- Don't make the dashboard auto-run scheduled jobs — that's still `schedule:run` via cron
- Don't try to cancel running jobs from the UI — queue job cancellation is messy in Laravel

### What to Prioritize

1. **LogsIngestion trait + retrofit existing commands** — this is the foundation
2. **Pipeline overview page** — health dots + last run status per source
3. **Run button** (single source) — the thing you need most
4. **Log detail modal** — see what happened
5. **Source detail page** — indicator coverage + history
6. **Run All** — convenience
7. **Polling for running state** — polish