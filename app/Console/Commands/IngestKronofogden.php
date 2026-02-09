<?php

namespace App\Console\Commands;

use App\Console\Concerns\LogsIngestion;
use App\Services\DataValidationService;
use App\Services\KronofogdenService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IngestKronofogden extends Command
{
    use LogsIngestion;

    protected $signature = 'ingest:kronofogden
        {--year=2024 : Year for the data (single year mode)}
        {--from= : Start year for multi-year ingestion}
        {--to= : End year for multi-year ingestion}
        {--source=kolada : Data source (kolada)}';

    protected $description = 'Ingest Kronofogden financial distress data from Kolada API';

    public function handle(KronofogdenService $service): int
    {
        $fromYear = $this->option('from') ? (int) $this->option('from') : null;
        $toYear = $this->option('to') ? (int) $this->option('to') : null;

        // Multi-year mode
        if ($fromYear && $toYear) {
            return $this->ingestMultiYear($service, $fromYear, $toYear);
        }

        // Single-year mode (backward-compatible)
        $year = (int) $this->option('year');

        return $this->ingestSingleYear($service, $year);
    }

    private function ingestMultiYear(KronofogdenService $service, int $fromYear, int $toYear): int
    {
        $this->startIngestionLog('kronofogden', 'ingest:kronofogden');
        $this->addStat('from_year', $fromYear);
        $this->addStat('to_year', $toYear);

        $this->info("Fetching Kronofogden data from Kolada API for years {$fromYear}-{$toYear}...");

        $allYearsData = $service->fetchFromKoladaMultiYear($fromYear, $toYear);
        $totalRecords = 0;

        foreach ($allYearsData as $year => $data) {
            if ($data->isEmpty()) {
                $this->warn("No data for year {$year}, skipping.");

                continue;
            }

            $records = $this->prepareRecords($data);
            $this->upsertRecords($records);
            $count = count($records);
            $totalRecords += $count;

            $this->info("  Year {$year}: {$data->count()} kommuner, {$count} records upserted.");
        }

        $totalInDb = DB::table('kronofogden_statistics')
            ->whereBetween('year', [$fromYear, $toYear])
            ->count();

        $this->info("Done. {$totalRecords} records upserted. {$totalInDb} total rows in DB for {$fromYear}-{$toYear}.");

        $this->processed = $totalRecords;
        $this->created = $totalRecords;
        $this->addStat('total_records', $totalRecords);
        $this->addStat('total_in_db', $totalInDb);
        $this->completeIngestionLog();

        return self::SUCCESS;
    }

    private function ingestSingleYear(KronofogdenService $service, int $year): int
    {
        $this->startIngestionLog('kronofogden', 'ingest:kronofogden');
        $this->addStat('year', $year);

        $this->info("Fetching Kronofogden data from Kolada API for year {$year}...");

        $data = $service->fetchFromKolada($year);

        if ($data->isEmpty()) {
            $this->error('No data returned from Kolada API.');
            $this->failIngestionLog('No data from Kolada');

            return self::FAILURE;
        }

        $this->info("Received data for {$data->count()} kommuner.");

        $records = $this->prepareRecords($data);

        $withDebt = collect($records)->whereNotNull('indebted_pct')->count();
        $withMedian = collect($records)->whereNotNull('median_debt_sek')->count();
        $withEviction = collect($records)->whereNotNull('eviction_rate_per_100k')->count();

        $this->info("Coverage: debt rate={$withDebt}, median debt={$withMedian}, eviction rate={$withEviction}");

        $this->upsertRecords($records);

        // Report stats
        $stats = DB::table('kronofogden_statistics')
            ->where('year', $year)
            ->selectRaw('COUNT(*) as count, MIN(indebted_pct) as min_pct, MAX(indebted_pct) as max_pct, AVG(indebted_pct) as avg_pct')
            ->first();

        $this->info("Stored {$stats->count} kommuner for year {$year}.");
        $this->info(sprintf(
            'Debt rate range: %.1f%% - %.1f%% (avg %.1f%%)',
            $stats->min_pct ?? 0,
            $stats->max_pct ?? 0,
            $stats->avg_pct ?? 0
        ));

        // Show top/bottom 3 for sanity check
        $top = DB::table('kronofogden_statistics')
            ->where('year', $year)
            ->whereNotNull('indebted_pct')
            ->orderByDesc('indebted_pct')
            ->limit(3)
            ->get(['municipality_name', 'indebted_pct']);

        $bottom = DB::table('kronofogden_statistics')
            ->where('year', $year)
            ->whereNotNull('indebted_pct')
            ->orderBy('indebted_pct')
            ->limit(3)
            ->get(['municipality_name', 'indebted_pct']);

        $this->info('Highest: '.implode(', ', $top->map(fn ($r) => "{$r->municipality_name} ({$r->indebted_pct}%)")->all()));
        $this->info('Lowest: '.implode(', ', $bottom->map(fn ($r) => "{$r->municipality_name} ({$r->indebted_pct}%)")->all()));

        $this->processed = $data->count();
        $this->created = count($records);
        $this->addStat('kommuner', $data->count());
        $this->addStat('with_debt_rate', $withDebt);
        $this->addStat('with_median_debt', $withMedian);
        $this->addStat('with_eviction_rate', $withEviction);
        $this->addStat('debt_rate_min', $stats->min_pct);
        $this->addStat('debt_rate_max', $stats->max_pct);
        $this->addStat('debt_rate_avg', round($stats->avg_pct, 2));
        $this->completeIngestionLog();

        $report = app(DataValidationService::class)->validateIngestion($this->ingestionLog, 'kronofogden', $year);
        $this->info("Validation: {$report->passedCount()} passed, {$report->failedCount()} failed");

        if ($report->hasBlockingFailures()) {
            $this->error('Blocking validation failures detected.');
            $this->error($report->summary());
            $this->ingestionLog->update(['status' => 'completed_with_errors']);

            return self::FAILURE;
        }

        if ($report->hasWarnings()) {
            $this->warn($report->summary());
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function prepareRecords(Collection $data): array
    {
        $records = [];
        $now = now();

        foreach ($data as $row) {
            $records[] = [
                'municipality_code' => $row['municipality_code'],
                'municipality_name' => $row['municipality_name'],
                'county_code' => $row['county_code'],
                'year' => $row['year'],
                'indebted_pct' => $row['indebted_pct'],
                'indebted_men_pct' => $row['indebted_men_pct'],
                'indebted_women_pct' => $row['indebted_women_pct'],
                'median_debt_sek' => $row['median_debt_sek'],
                'eviction_rate_per_100k' => $row['eviction_rate_per_100k'],
                'data_source' => $row['data_source'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $records;
    }

    private function upsertRecords(array $records): void
    {
        foreach (array_chunk($records, 1000) as $chunk) {
            DB::table('kronofogden_statistics')->upsert(
                $chunk,
                ['municipality_code', 'year'],
                ['municipality_name', 'county_code', 'indebted_pct', 'indebted_men_pct',
                    'indebted_women_pct', 'median_debt_sek', 'eviction_rate_per_100k',
                    'data_source', 'updated_at']
            );
        }
    }
}
