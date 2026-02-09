<?php

namespace App\Console\Commands;

use App\Console\Concerns\LogsIngestion;
use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Services\CrosswalkService;
use App\Services\ScbApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IngestScbHistorical extends Command
{
    use LogsIngestion;

    protected $signature = 'ingest:scb-historical
        {--from=2019 : Start year}
        {--to=2023 : End year}
        {--indicator= : Specific indicator slug, or all SCB indicators if omitted}
        {--skip-normalize : Skip normalization after ingestion}';

    protected $description = 'Ingest historical SCB data (old DeSO codes) with crosswalk mapping to DeSO 2025';

    /** @var array<string, string> */
    private const INDICATOR_UNITS = [
        'median_income' => 'SEK',
        'low_economic_standard_pct' => 'percent',
        'foreign_background_pct' => 'percent',
        'population' => 'number',
        'employment_rate' => 'percent',
        'education_post_secondary_pct' => 'percent',
        'education_below_secondary_pct' => 'percent',
        'rental_tenure_pct' => 'percent',
    ];

    public function handle(ScbApiService $scbService, CrosswalkService $crosswalkService): int
    {
        $from = (int) $this->option('from');
        $to = (int) $this->option('to');

        if ($from > $to) {
            $this->error("--from ({$from}) must be <= --to ({$to})");

            return self::FAILURE;
        }

        $indicatorSlugs = $this->resolveIndicatorSlugs($scbService);
        if (empty($indicatorSlugs)) {
            $this->error('No indicators to process.');

            return self::FAILURE;
        }

        $this->startIngestionLog('scb_historical', 'ingest:scb-historical');
        $this->addStat('indicators', $indicatorSlugs);
        $this->addStat('year_range', "{$from}-{$to}");

        $indicators = Indicator::query()
            ->whereIn('slug', $indicatorSlugs)
            ->get()
            ->keyBy('slug');

        try {
            foreach ($indicatorSlugs as $slug) {
                $indicator = $indicators->get($slug);
                if (! $indicator) {
                    $this->addWarning("Indicator '{$slug}' not found in database, skipping");

                    continue;
                }

                $availableYears = $scbService->getHistoricalYears($slug);
                $unit = self::INDICATOR_UNITS[$slug] ?? $indicator->unit;

                for ($year = $from; $year <= $to; $year++) {
                    if (! in_array($year, $availableYears)) {
                        $this->warn("  {$slug} year {$year} - not available, skipping");
                        $this->skipped++;

                        continue;
                    }

                    $this->ingestHistoricalYear($scbService, $crosswalkService, $indicator, $slug, $year, $unit);

                    // Rate limiting: SCB allows 30 requests per 10 seconds
                    usleep(400_000);
                }
            }

            $this->completeIngestionLog();

            $this->newLine();
            $this->info("Historical ingestion complete: {$this->processed} processed, {$this->created} created, {$this->updated} updated, {$this->skipped} skipped.");

            // Normalize each year independently
            if (! $this->option('skip-normalize')) {
                $this->newLine();
                $this->info('Normalizing historical years...');
                for ($year = $from; $year <= $to; $year++) {
                    $this->call('normalize:indicators', ['--year' => $year]);
                }
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->failIngestionLog($e->getMessage());
            $this->error("Historical ingestion failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    private function ingestHistoricalYear(
        ScbApiService $scbService,
        CrosswalkService $crosswalkService,
        Indicator $indicator,
        string $slug,
        int $year,
        string $unit,
    ): void {
        $this->info("Ingesting {$slug} for {$year}...");

        try {
            $oldValues = $scbService->fetchHistorical($slug, $year);
        } catch (\Exception $e) {
            $this->addWarning("Failed to fetch {$slug} for {$year}: {$e->getMessage()}");

            return;
        }

        // Filter out null values before crosswalk
        $oldValues = array_filter($oldValues, fn ($v) => $v !== null);
        $this->info('  Fetched '.count($oldValues).' values from API (old DeSO codes)');
        $this->addStat("api_{$slug}_{$year}", count($oldValues));

        // Map through crosswalk: old DeSO codes -> new DeSO 2025 codes
        $newValues = $crosswalkService->bulkMapOldToNew($oldValues, $unit);
        $this->info('  Mapped to '.count($newValues).' new DeSO codes via crosswalk');

        if (empty($newValues)) {
            $this->addWarning("No values mapped for {$slug} year {$year}");

            return;
        }

        // Track counts before upsert
        $existingCount = IndicatorValue::query()
            ->where('indicator_id', $indicator->id)
            ->where('year', $year)
            ->count();

        // Upsert in chunks
        $now = now()->toDateTimeString();
        $rows = [];
        foreach ($newValues as $desoCode => $value) {
            $rows[] = [
                'deso_code' => $desoCode,
                'indicator_id' => $indicator->id,
                'year' => $year,
                'raw_value' => round($value, 4),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

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

        $createdNow = $newCount - $existingCount;
        $updatedNow = count($rows) - $createdNow;

        $this->created += $createdNow;
        $this->updated += $updatedNow;
        $this->processed += count($rows);

        $this->info("  Stored: {$createdNow} created, {$updatedNow} updated");
    }

    /**
     * @return string[]
     */
    private function resolveIndicatorSlugs(ScbApiService $scbService): array
    {
        if ($slug = $this->option('indicator')) {
            // Validate the indicator exists in the system
            if (! array_key_exists($slug, self::INDICATOR_UNITS)) {
                $this->error("Unknown SCB indicator: {$slug}");

                return [];
            }

            return [$slug];
        }

        // All SCB indicators that have historical data
        return array_keys(self::INDICATOR_UNITS);
    }
}
