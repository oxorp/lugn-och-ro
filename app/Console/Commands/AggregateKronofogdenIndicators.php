<?php

namespace App\Console\Commands;

use App\Models\IngestionLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AggregateKronofogdenIndicators extends Command
{
    protected $signature = 'aggregate:kronofogden-indicators
        {--year=2024 : Year for the data}';

    protected $description = 'Create indicator values from Kronofogden disaggregation results';

    public function handle(): int
    {
        $year = (int) $this->option('year');

        $log = IngestionLog::query()->create([
            'source' => 'kronofogden_indicators',
            'command' => 'aggregate:kronofogden-indicators',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => ['year' => $year],
        ]);

        // Ensure indicators exist
        $indicators = $this->ensureIndicators();
        $this->info('Kronofogden indicators: '.implode(', ', array_keys($indicators)));

        // Load disaggregation results
        $results = DB::table('debt_disaggregation_results')
            ->where('year', $year)
            ->get();

        if ($results->isEmpty()) {
            $this->error('No disaggregation results found. Run disaggregate:kronofogden first.');
            $log->update(['status' => 'failed', 'error_message' => 'No disaggregation results', 'completed_at' => now()]);

            return self::FAILURE;
        }

        $this->info("Found {$results->count()} DeSO disaggregation results.");

        // Load kommun-level median debt for flat assignment
        $kommunMedianDebt = DB::table('kronofogden_statistics')
            ->where('year', $year)
            ->whereNotNull('median_debt_sek')
            ->pluck('median_debt_sek', 'municipality_code');

        $records = [];
        $now = now();

        foreach ($results as $row) {
            // debt_rate_pct
            if ($row->estimated_debt_rate !== null && isset($indicators['debt_rate_pct'])) {
                $records[] = [
                    'deso_code' => $row->deso_code,
                    'indicator_id' => $indicators['debt_rate_pct'],
                    'year' => $year,
                    'raw_value' => round((float) $row->estimated_debt_rate, 4),
                    'normalized_value' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // eviction_rate (per 100k)
            if ($row->estimated_eviction_rate !== null && isset($indicators['eviction_rate'])) {
                $records[] = [
                    'deso_code' => $row->deso_code,
                    'indicator_id' => $indicators['eviction_rate'],
                    'year' => $year,
                    'raw_value' => round((float) $row->estimated_eviction_rate, 4),
                    'normalized_value' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // median_debt_sek (kommun-level, flat for all DeSOs in kommun)
            if (isset($indicators['median_debt_sek'])) {
                $medianDebt = $kommunMedianDebt[$row->municipality_code] ?? null;
                if ($medianDebt !== null) {
                    $records[] = [
                        'deso_code' => $row->deso_code,
                        'indicator_id' => $indicators['median_debt_sek'],
                        'year' => $year,
                        'raw_value' => round((float) $medianDebt, 0),
                        'normalized_value' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        $this->info('Upserting '.count($records).' indicator value records...');

        foreach (array_chunk($records, 1000) as $chunk) {
            DB::table('indicator_values')->upsert(
                $chunk,
                ['deso_code', 'indicator_id', 'year'],
                ['raw_value', 'updated_at']
            );
        }

        // Report
        $counts = DB::table('indicator_values')
            ->join('indicators', 'indicators.id', '=', 'indicator_values.indicator_id')
            ->where('indicators.source', 'kronofogden')
            ->where('indicator_values.year', $year)
            ->groupBy('indicators.slug')
            ->selectRaw('indicators.slug, COUNT(*) as cnt, AVG(indicator_values.raw_value) as avg_val, MIN(indicator_values.raw_value) as min_val, MAX(indicator_values.raw_value) as max_val')
            ->get();

        foreach ($counts as $row) {
            $this->info(sprintf(
                '  %s: %d values (%.2f - %.2f, avg %.2f)',
                $row->slug,
                $row->cnt,
                $row->min_val,
                $row->max_val,
                $row->avg_val
            ));
        }

        $log->update([
            'status' => 'completed',
            'records_processed' => $results->count(),
            'records_created' => count($records),
            'completed_at' => now(),
            'metadata' => [
                'year' => $year,
                'total_records' => count($records),
            ],
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array<string, int> slug => indicator ID
     */
    private function ensureIndicators(): array
    {
        $slugs = ['debt_rate_pct', 'eviction_rate', 'median_debt_sek'];

        $indicators = DB::table('indicators')
            ->whereIn('slug', $slugs)
            ->pluck('id', 'slug')
            ->toArray();

        if (count($indicators) < count($slugs)) {
            $this->warn('Missing Kronofogden indicators. Running seeder...');
            $this->call('db:seed', ['--class' => 'KronofogdenIndicatorSeeder', '--no-interaction' => true]);

            $indicators = DB::table('indicators')
                ->whereIn('slug', $slugs)
                ->pluck('id', 'slug')
                ->toArray();
        }

        return $indicators;
    }
}
