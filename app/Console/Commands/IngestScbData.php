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
        {--from= : Start year for range ingestion}
        {--to= : End year for range ingestion}
        {--all : Fetch all active SCB indicators}';

    protected $description = 'Ingest demographic data from SCB PX-Web API into indicator_values';

    public function handle(ScbApiService $scbService): int
    {
        $indicators = $this->resolveIndicators($scbService);
        if ($indicators->isEmpty()) {
            $this->error('No indicators to process.');

            return self::FAILURE;
        }

        $years = $this->resolveYears($scbService, $indicators->first());

        $log = IngestionLog::query()->create([
            'source' => 'scb',
            'command' => 'ingest:scb',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => [
                'indicators' => $indicators->pluck('slug')->toArray(),
                'years' => $years,
            ],
        ]);

        $desoCodes = DesoArea::query()->pluck('deso_code')->flip()->toArray();
        $codeMappings = DB::table('deso_code_mappings')->pluck('new_code', 'old_code')->toArray();

        $totalCreated = 0;
        $totalUpdated = 0;
        $totalProcessed = 0;

        try {
            foreach ($indicators as $indicator) {
                foreach ($years as $year) {
                    $this->info("Fetching {$indicator->slug} for year {$year}...");

                    try {
                        $data = $scbService->fetchIndicator($indicator->slug, $year);
                    } catch (\Exception $e) {
                        $this->warn("  Failed to fetch {$indicator->slug} for {$year}: {$e->getMessage()}");

                        continue;
                    }
                    $this->info('  Received '.count($data).' DeSO values from API');

                    $rows = [];
                    $unmatched = 0;
                    $mapped = 0;
                    $now = now()->toDateTimeString();

                    foreach ($data as $desoCode => $value) {
                        $canonicalCode = $desoCode;

                        if (! isset($desoCodes[$desoCode])) {
                            // Try code mapping table (for old DeSO 2018 codes)
                            if (isset($codeMappings[$desoCode])) {
                                $canonicalCode = $codeMappings[$desoCode];
                                $mapped++;
                            } else {
                                $unmatched++;

                                continue;
                            }
                        }

                        $rows[] = [
                            'deso_code' => $canonicalCode,
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

                    $this->info("  Matched: {$matched} (mapped: {$mapped}), Unmatched: {$unmatched}, Created: {$created}, Updated: {$updated}");

                    if ($unmatched > 0) {
                        $this->warn("  {$unmatched} DeSO codes from API not found (likely split/merged areas or RegSO codes)");
                    }

                    // Rate limiting between API calls
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

            // Run validation for the last year only
            $lastYear = end($years);
            $report = app(DataValidationService::class)->validateIngestion($log, 'scb', $lastYear);

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
     * @return int[]
     */
    private function resolveYears(ScbApiService $scbService, Indicator $firstIndicator): array
    {
        if ($this->option('from') && $this->option('to')) {
            $from = (int) $this->option('from');
            $to = (int) $this->option('to');

            return range($from, $to);
        }

        if ($this->option('year')) {
            return [(int) $this->option('year')];
        }

        // Default: latest available year
        $latest = $scbService->findLatestYear($firstIndicator->slug) ?? now()->year - 1;

        return [$latest];
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
