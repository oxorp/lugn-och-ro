<?php

namespace App\Console\Commands;

use App\Models\IngestionLog;
use App\Services\KronofogdenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IngestKronofogden extends Command
{
    protected $signature = 'ingest:kronofogden
        {--year=2024 : Year for the data}
        {--source=kolada : Data source (kolada)}';

    protected $description = 'Ingest Kronofogden financial distress data from Kolada API';

    public function handle(KronofogdenService $service): int
    {
        $year = (int) $this->option('year');

        $log = IngestionLog::query()->create([
            'source' => 'kronofogden',
            'command' => 'ingest:kronofogden',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => ['year' => $year],
        ]);

        $this->info("Fetching Kronofogden data from Kolada API for year {$year}...");

        $data = $service->fetchFromKolada($year);

        if ($data->isEmpty()) {
            $this->error('No data returned from Kolada API.');
            $log->update([
                'status' => 'failed',
                'error_message' => 'No data from Kolada',
                'completed_at' => now(),
            ]);

            return self::FAILURE;
        }

        $this->info("Received data for {$data->count()} kommuner.");

        // Prepare records for upsert
        $records = [];
        $now = now();
        $withDebt = 0;
        $withEviction = 0;
        $withMedian = 0;

        foreach ($data as $row) {
            if ($row['indebted_pct'] !== null) {
                $withDebt++;
            }
            if ($row['eviction_rate_per_100k'] !== null) {
                $withEviction++;
            }
            if ($row['median_debt_sek'] !== null) {
                $withMedian++;
            }

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

        $this->info("Coverage: debt rate={$withDebt}, median debt={$withMedian}, eviction rate={$withEviction}");

        // Upsert in chunks
        foreach (array_chunk($records, 1000) as $chunk) {
            DB::table('kronofogden_statistics')->upsert(
                $chunk,
                ['municipality_code', 'year'],
                ['municipality_name', 'county_code', 'indebted_pct', 'indebted_men_pct',
                    'indebted_women_pct', 'median_debt_sek', 'eviction_rate_per_100k',
                    'data_source', 'updated_at']
            );
        }

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

        $log->update([
            'status' => 'completed',
            'records_processed' => $data->count(),
            'records_created' => count($records),
            'completed_at' => now(),
            'metadata' => [
                'year' => $year,
                'kommuner' => $data->count(),
                'with_debt_rate' => $withDebt,
                'with_median_debt' => $withMedian,
                'with_eviction_rate' => $withEviction,
                'debt_rate_range' => [
                    'min' => $stats->min_pct,
                    'max' => $stats->max_pct,
                    'avg' => round($stats->avg_pct, 2),
                ],
            ],
        ]);

        return self::SUCCESS;
    }
}
