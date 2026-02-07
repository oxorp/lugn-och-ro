<?php

namespace App\Console\Commands;

use App\Models\IngestionLog;
use App\Models\ScoreVersion;
use App\Services\DataValidationService;
use App\Services\ScoreDriftDetector;
use Illuminate\Console\Command;

class RunPipeline extends Command
{
    protected $signature = 'pipeline:run
        {--source=all : Source to ingest (all, scb, skolverket, bra, kronofogden)}
        {--year= : Year to process (defaults to previous year)}
        {--auto-publish : Automatically publish if all checks pass}';

    protected $description = 'Run the full data pipeline: ingest, validate, score, check, publish';

    public function handle(): int
    {
        $year = (int) ($this->option('year') ?: now()->year - 1);
        $source = $this->option('source');

        $this->info("=== Pipeline Run: year={$year}, source={$source} ===");
        $this->newLine();

        // Stage 1: Ingest
        $this->info('=== Stage 1: Ingestion ===');
        if ($this->shouldIngest($source, 'scb')) {
            $this->call('ingest:scb', ['--all' => true, '--year' => $year]);
        }
        if ($this->shouldIngest($source, 'skolverket')) {
            $this->call('ingest:skolverket-schools');
            $this->call('ingest:skolverket-stats');
        }
        if ($this->shouldIngest($source, 'bra')) {
            $this->call('ingest:bra-crime', ['--year' => $year]);
        }
        if ($this->shouldIngest($source, 'kronofogden')) {
            $this->call('ingest:kronofogden', ['--year' => $year]);
        }

        // Stage 2: Validate
        $this->newLine();
        $this->info('=== Stage 2: Validation ===');
        $validator = app(DataValidationService::class);
        $hasBlockingFailures = false;

        $recentLogs = IngestionLog::query()
            ->where('started_at', '>=', now()->subHour())
            ->where('status', 'completed')
            ->get();

        foreach ($recentLogs as $log) {
            $report = $validator->validateIngestion($log, $log->source, $year);
            $this->info("  {$log->source}: {$report->passedCount()} passed, {$report->failedCount()} failed");

            if ($report->hasBlockingFailures()) {
                $this->error("  Blocking failures in {$log->source}!");
                $this->error($report->summary());
                $hasBlockingFailures = true;
            }
        }

        if ($hasBlockingFailures) {
            $this->error('Pipeline halted: blocking validation failures.');

            return self::FAILURE;
        }

        // Stage 3: Aggregate & Disaggregate
        $this->newLine();
        $this->info('=== Stage 3: Aggregate & Process ===');
        if ($this->shouldIngest($source, 'skolverket')) {
            $this->call('aggregate:school-indicators', [
                '--academic-year' => '2020/21',
                '--calendar-year' => $year,
            ]);
        }
        if ($this->shouldIngest($source, 'bra')) {
            $this->call('disaggregate:crime', ['--year' => $year]);
        }
        if ($this->shouldIngest($source, 'kronofogden')) {
            $this->call('disaggregate:kronofogden', ['--year' => $year]);
            $this->call('aggregate:kronofogden-indicators', ['--year' => $year]);
        }

        // Stage 4: Normalize
        $this->newLine();
        $this->info('=== Stage 4: Normalize ===');
        $this->call('normalize:indicators', ['--year' => $year]);

        // Stage 5: Compute scores (creates new version in 'pending' state)
        $this->newLine();
        $this->info('=== Stage 5: Compute Scores ===');
        $this->call('compute:scores', ['--year' => $year]);

        // Stage 5b: Compute trends
        $this->newLine();
        $this->info('=== Stage 5b: Compute Trends ===');
        $this->call('compute:trends', [
            '--base-year' => $year - 2,
            '--end-year' => $year,
        ]);

        $version = ScoreVersion::query()
            ->where('year', $year)
            ->latest('computed_at')
            ->first();

        if (! $version) {
            $this->error('No score version created. Something went wrong.');

            return self::FAILURE;
        }

        // Stage 6: Sentinel checks
        $this->newLine();
        $this->info('=== Stage 6: Sentinel Checks ===');
        $sentinelResult = $this->call('check:sentinels', ['--year' => $year]);
        if ($sentinelResult !== self::SUCCESS) {
            $version->update(['status' => 'pending', 'sentinel_results' => ['status' => 'FAILED']]);
            $this->error('Sentinel checks failed. Scores NOT published.');

            return self::FAILURE;
        }
        $version->update(['status' => 'validated', 'sentinel_results' => ['status' => 'PASSED']]);

        // Stage 7: Drift detection
        $previousVersion = ScoreVersion::query()
            ->where('year', $year)
            ->where('status', 'published')
            ->latest('published_at')
            ->first();

        if ($previousVersion) {
            $this->newLine();
            $this->info('=== Stage 7: Drift Analysis ===');
            $drift = app(ScoreDriftDetector::class)->detect($version, $previousVersion);
            $this->info("  Mean drift: {$drift->meanDrift} | Max drift: {$drift->maxDrift} | Large drifts: ".count($drift->areasWithLargeDrift));

            if ($drift->hasSystemicShift()) {
                $this->warn('  Large systemic shift detected. Manual review recommended.');
                if (! $this->option('auto-publish')) {
                    $this->info("Scores validated but not published. Run: php artisan scores:publish --version={$version->id}");

                    return self::SUCCESS;
                }
            }
        }

        // Stage 8: Publish
        if ($this->option('auto-publish') || ! $previousVersion) {
            $this->call('scores:publish', ['--score-version' => $version->id]);
            $this->info("Scores published as version #{$version->id}");
        } else {
            $this->info("Scores validated but not published. Run: php artisan scores:publish --version={$version->id}");
        }

        // Stage 9: Freshness check
        $this->newLine();
        $this->info('=== Stage 9: Freshness Check ===');
        $this->call('check:freshness');

        $this->newLine();
        $this->info('=== Pipeline complete ===');

        return self::SUCCESS;
    }

    private function shouldIngest(string $selectedSource, string $target): bool
    {
        return $selectedSource === 'all' || $selectedSource === $target;
    }
}
