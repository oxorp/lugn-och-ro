<?php

namespace App\Http\Controllers;

use App\Jobs\RunFullPipeline;
use App\Jobs\RunIngestionCommand;
use App\Models\CompositeScore;
use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Models\IngestionLog;
use App\Models\School;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminPipelineController extends Controller
{
    public function index(): Response
    {
        $sources = collect(config('pipeline.sources'))->map(function (array $config, string $key) {
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
                    'started_at' => $lastRun->started_at?->toISOString(),
                    'completed_at' => $lastRun->completed_at?->toISOString(),
                    'duration_seconds' => $lastRun->duration_seconds,
                    'records_processed' => $lastRun->records_processed,
                    'records_created' => $lastRun->records_created,
                    'records_updated' => $lastRun->records_updated,
                    'records_failed' => $lastRun->records_failed,
                    'summary' => $lastRun->summary,
                    'has_warnings' => ! empty($lastRun->warnings),
                    'has_errors' => $lastRun->status === 'failed',
                ] : null,
                'last_success_at' => $lastSuccess?->completed_at?->toISOString(),
                'running' => $runningNow,
                'indicator_count' => count($config['indicators'] ?? []),
                'commands' => array_keys($config['commands']),
            ];
        });

        $overallHealth = $sources->every(fn (array $s) => $s['health'] === 'healthy')
            ? 'healthy'
            : ($sources->contains(fn (array $s) => $s['health'] === 'critical') ? 'critical' : 'warning');

        $stats = [
            'total_ingestion_runs' => IngestionLog::count(),
            'runs_last_7_days' => IngestionLog::where('started_at', '>=', now()->subDays(7))->count(),
            'failed_last_7_days' => IngestionLog::where('started_at', '>=', now()->subDays(7))
                ->where('status', 'failed')->count(),
            'total_indicators' => Indicator::where('is_active', true)->count(),
            'total_desos_with_scores' => CompositeScore::distinct('deso_code')->count('deso_code'),
            'total_schools' => School::where('status', 'active')->count(),
        ];

        $recentLogs = IngestionLog::latest('started_at')
            ->limit(20)
            ->get()
            ->map(fn (IngestionLog $log) => [
                'id' => $log->id,
                'source' => $log->source,
                'command' => $log->command,
                'status' => $log->status,
                'trigger' => $log->trigger,
                'started_at' => $log->started_at?->toISOString(),
                'completed_at' => $log->completed_at?->toISOString(),
                'duration_seconds' => $log->duration_seconds,
                'records_processed' => $log->records_processed,
                'records_created' => $log->records_created,
                'records_updated' => $log->records_updated,
                'records_failed' => $log->records_failed,
                'records_skipped' => $log->records_skipped,
                'summary' => $log->summary,
                'error_message' => $log->error_message,
                'warnings' => $log->warnings,
                'stats' => $log->stats,
                'memory_peak_mb' => $log->memory_peak_mb,
            ]);

        return Inertia::render('admin/pipeline', [
            'sources' => $sources->values(),
            'overallHealth' => $overallHealth,
            'stats' => $stats,
            'pipelineOrder' => config('pipeline.pipeline_order'),
            'recentLogs' => $recentLogs,
        ]);
    }

    public function show(string $source): Response
    {
        $config = config("pipeline.sources.{$source}");
        abort_unless($config, 404);

        $logs = IngestionLog::where('source', $source)
            ->latest('started_at')
            ->limit(50)
            ->get()
            ->map(fn (IngestionLog $log) => [
                'id' => $log->id,
                'command' => $log->command,
                'status' => $log->status,
                'trigger' => $log->trigger,
                'triggered_by' => $log->triggered_by,
                'started_at' => $log->started_at?->toISOString(),
                'completed_at' => $log->completed_at?->toISOString(),
                'duration_seconds' => $log->duration_seconds,
                'records_processed' => $log->records_processed,
                'records_created' => $log->records_created,
                'records_updated' => $log->records_updated,
                'records_failed' => $log->records_failed,
                'records_skipped' => $log->records_skipped,
                'summary' => $log->summary,
                'error_message' => $log->error_message,
                'warnings' => $log->warnings,
                'stats' => $log->stats,
                'memory_peak_mb' => $log->memory_peak_mb,
            ]);

        $indicators = Indicator::whereIn('slug', $config['indicators'] ?? [])
            ->get()
            ->map(function (Indicator $indicator) {
                $latestYear = IndicatorValue::where('indicator_id', $indicator->id)
                    ->max('year');
                $coverage = $latestYear
                    ? IndicatorValue::where('indicator_id', $indicator->id)
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

        $runningNow = IngestionLog::where('source', $source)
            ->where('status', 'running')
            ->exists();

        $lastSuccess = IngestionLog::where('source', $source)
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();

        return Inertia::render('admin/pipeline-source', [
            'source' => array_merge($config, [
                'key' => $source,
                'health' => $this->computeHealth($config, $lastSuccess),
                'running' => $runningNow,
                'last_success_at' => $lastSuccess?->completed_at?->toISOString(),
            ]),
            'logs' => $logs,
            'indicators' => $indicators,
        ]);
    }

    public function run(Request $request, string $source): \Illuminate\Http\RedirectResponse
    {
        $config = config("pipeline.sources.{$source}");
        abort_unless($config, 404);

        $commandKey = $request->input('command', 'ingest');
        $commandConfig = $config['commands'][$commandKey] ?? null;
        abort_unless($commandConfig, 400, "Unknown command: {$commandKey}");

        $artisanCommand = $commandConfig['command'];
        $defaultOptions = $commandConfig['options'] ?? [];

        RunIngestionCommand::dispatch(
            source: $source,
            command: $artisanCommand,
            options: $defaultOptions,
            triggeredBy: 'admin',
        );

        return back()->with('message', "Started {$config['name']} — {$commandKey}. Refresh to see progress.");
    }

    public function runAll(Request $request): \Illuminate\Http\RedirectResponse
    {
        $year = $request->input('year', now()->year - 1);

        RunFullPipeline::dispatch(
            triggeredBy: 'admin',
            options: ['year' => $year],
        );

        return back()->with('message', 'Full pipeline started. This may take several minutes.');
    }

    public function log(IngestionLog $log): \Illuminate\Http\JsonResponse
    {
        return response()->json($log);
    }

    private function computeHealth(array $config, ?IngestionLog $lastSuccess): string
    {
        if (! $lastSuccess) {
            return 'unknown';
        }
        if ($config['stale_after_days'] === null) {
            return 'healthy';
        }

        $daysSinceSuccess = $lastSuccess->completed_at->diffInDays(now());

        if ($daysSinceSuccess > $config['stale_after_days']) {
            return 'critical';
        }
        if ($daysSinceSuccess > $config['stale_after_days'] * 0.8) {
            return 'warning';
        }

        return 'healthy';
    }
}
