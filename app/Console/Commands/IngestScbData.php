<?php

namespace App\Console\Commands;

use App\Models\DesoArea;
use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Models\IngestionLog;
use App\Services\DataValidationService;
use App\Services\ScbApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IngestScbData extends Command
{
    protected $signature = 'ingest:scb
        {--indicator= : Specific indicator slug to fetch}
        {--year= : Specific year to fetch (defaults to latest available)}
        {--all : Fetch all active SCB indicators}';

    protected $description = 'Ingest demographic data from SCB PX-Web API into indicator_values';

    public function handle(ScbApiService $scbService): int
    {
        $indicators = $this->resolveIndicators($scbService);
        if ($indicators->isEmpty()) {
            $this->error('No indicators to process.');

            return self::FAILURE;
        }

        $log = IngestionLog::query()->create([
            'source' => 'scb',
            'command' => 'ingest:scb',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => ['indicators' => $indicators->pluck('slug')->toArray()],
        ]);

        $desoCodes = DesoArea::query()->pluck('deso_code')->flip()->toArray();
        $totalCreated = 0;
        $totalUpdated = 0;
        $totalProcessed = 0;

        try {
            foreach ($indicators as $indicator) {
                $year = (int) ($this->option('year') ?: $scbService->findLatestYear($indicator->slug) ?: now()->year - 1);

                $this->info("Fetching {$indicator->slug} for year {$year}...");

                $data = $scbService->fetchIndicator($indicator->slug, $year);
                $this->info('  Received '.count($data).' DeSO values from API');

                $rows = [];
                $unmatched = 0;
                $now = now()->toDateTimeString();

                foreach ($data as $desoCode => $value) {
                    if (! isset($desoCodes[$desoCode])) {
                        $unmatched++;

                        continue;
                    }

                    $rows[] = [
                        'deso_code' => $desoCode,
                        'indicator_id' => $indicator->id,
                        'year' => $year,
                        'raw_value' => $value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                $matched = count($rows);
                $existingCount = IndicatorValue::query()
                    ->where('indicator_id', $indicator->id)
                    ->where('year', $year)
                    ->count();

                foreach (array_chunk($rows, 1000) as $chunk) {
                    DB::table('indicator_values')->upsert(
                        $chunk,
                        ['deso_code', 'indicator_id', 'year'],
                        ['raw_value', 'updated_at']
                    );
                }

                $newCount = IndicatorValue::query()
                    ->where('indicator_id', $indicator->id)
                    ->where('year', $year)
                    ->count();

                $created = $newCount - $existingCount;
                $updated = $matched - $created;

                $totalCreated += $created;
                $totalUpdated += $updated;
                $totalProcessed += $matched;

                $this->info("  Matched: {$matched}, Unmatched: {$unmatched}, Created: {$created}, Updated: {$updated}");

                if ($unmatched > 0) {
                    $this->warn("  {$unmatched} DeSO codes from API not found in our database (likely RegSO or old DeSO 2018 codes)");
                }

                // Rate limiting: sleep between API calls
                if ($indicators->count() > 1) {
                    usleep(500_000);
                }
            }

            $log->update([
                'status' => 'completed',
                'records_processed' => $totalProcessed,
                'records_created' => $totalCreated,
                'records_updated' => $totalUpdated,
                'completed_at' => now(),
            ]);

            $this->newLine();
            $this->info("Ingestion complete: {$totalProcessed} processed, {$totalCreated} created, {$totalUpdated} updated.");

            // Run validation
            $year = (int) ($this->option('year') ?: now()->year - 1);
            $report = app(DataValidationService::class)->validateIngestion($log, 'scb', $year);

            $this->newLine();
            $this->info("Validation: {$report->passedCount()} passed, {$report->failedCount()} failed");

            if ($report->hasBlockingFailures()) {
                $this->error('Blocking validation failures detected. Scoring will not proceed.');
                $this->error($report->summary());
                $log->update(['status' => 'completed_with_errors', 'metadata' => array_merge($log->metadata ?? [], ['validation' => $report->toArray()])]);

                return self::FAILURE;
            }

            if ($report->hasWarnings()) {
                $this->warn('Validation warnings:');
                $this->warn($report->summary());
            }

            $log->update(['metadata' => array_merge($log->metadata ?? [], ['validation' => $report->toArray()])]);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'records_processed' => $totalProcessed,
                'records_created' => $totalCreated,
                'records_updated' => $totalUpdated,
                'completed_at' => now(),
            ]);

            $this->error("Ingestion failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Indicator>
     */
    private function resolveIndicators(ScbApiService $scbService): \Illuminate\Database\Eloquent\Collection
    {
        if ($slug = $this->option('indicator')) {
            if (! $scbService->getIndicatorConfig($slug)) {
                $this->error("No SCB configuration for indicator: {$slug}");

                return new \Illuminate\Database\Eloquent\Collection;
            }

            return Indicator::query()
                ->where('slug', $slug)
                ->where('is_active', true)
                ->get();
        }

        if ($this->option('all')) {
            $availableSlugs = $scbService->getAvailableSlugs();

            return Indicator::query()
                ->where('source', 'scb')
                ->where('is_active', true)
                ->whereIn('slug', $availableSlugs)
                ->get();
        }

        $this->error('Specify --indicator=slug or --all');

        return new \Illuminate\Database\Eloquent\Collection;
    }
}
