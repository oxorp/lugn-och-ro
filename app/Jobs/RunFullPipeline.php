<?php

namespace App\Jobs;

use App\Models\IngestionLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RunFullPipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

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
            'command' => 'pipeline:run-all',
            'status' => 'running',
            'trigger' => 'pipeline',
            'triggered_by' => $this->triggeredBy,
            'started_at' => now(),
        ]);

        $results = [];

        try {
            foreach ($order as $sourceKey) {
                $config = config("pipeline.sources.{$sourceKey}");
                if (! $config) {
                    continue;
                }

                foreach ($config['commands'] as $name => $commandConfig) {
                    $command = $commandConfig['command'];
                    $options = $commandConfig['options'] ?? [];

                    // Add --year to commands that accept it
                    if ($this->commandAcceptsYear($command) && ! isset($options['--year'])) {
                        $options['--year'] = $year;
                    }

                    // Add --calendar-year for school aggregation
                    if ($command === 'aggregate:school-indicators' && ! isset($options['--calendar-year'])) {
                        $options['--calendar-year'] = $year;
                    }

                    $stepStart = microtime(true);
                    $exitCode = Artisan::call($command, $options);
                    $output = Artisan::output();

                    $results[] = [
                        'source' => $sourceKey,
                        'command' => $command,
                        'step' => $name,
                        'exit_code' => $exitCode,
                        'duration' => round(microtime(true) - $stepStart, 1),
                        'output_preview' => Str::limit($output, 500),
                    ];

                    if ($exitCode !== 0) {
                        Log::warning("Pipeline step failed: {$command}", [
                            'exit_code' => $exitCode,
                            'output' => $output,
                        ]);
                    }
                }
            }

            $failedCount = collect($results)->where('exit_code', '!=', 0)->count();
            $successCount = collect($results)->where('exit_code', 0)->count();

            $pipelineLog->update([
                'status' => $failedCount === 0 ? 'completed' : 'failed',
                'completed_at' => now(),
                'duration_seconds' => (int) $pipelineLog->started_at->diffInSeconds(now()),
                'records_processed' => count($results),
                'records_failed' => $failedCount,
                'stats' => $results,
                'summary' => "{$successCount} steps completed, {$failedCount} failed",
            ]);
        } catch (\Throwable $e) {
            $pipelineLog->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
                'stats' => $results,
                'duration_seconds' => (int) $pipelineLog->started_at->diffInSeconds(now()),
            ]);

            throw $e;
        }
    }

    private function commandAcceptsYear(string $command): bool
    {
        return in_array($command, [
            'ingest:scb',
            'ingest:bra-crime',
            'ingest:kronofogden',
            'ingest:ntu',
            'ingest:vulnerability-areas',
            'disaggregate:crime',
            'disaggregate:kronofogden',
            'aggregate:kronofogden-indicators',
            'normalize:indicators',
            'compute:scores',
        ]);
    }
}
