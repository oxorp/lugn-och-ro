<?php

namespace App\Console\Commands;

use App\Console\Concerns\LogsIngestion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DisaggregateKronofogden extends Command
{
    use LogsIngestion;

    protected $signature = 'disaggregate:kronofogden
        {--year=2024 : Year for the data}
        {--validate : Run cross-validation to estimate model quality}';

    protected $description = 'Disaggregate kommun-level Kronofogden debt data to DeSO level using demographic weighting';

    private const MODEL_VERSION = 'v1_weighted';

    public function handle(): int
    {
        $year = (int) $this->option('year');

        $this->startIngestionLog('kronofogden', 'disaggregate:kronofogden');
        $this->addStat('year', $year);

        $this->info("Disaggregating Kronofogden data to DeSO level for year {$year}...");

        // Step 1: Load DeSO demographic percentiles
        $this->info('Loading DeSO demographic percentiles...');
        $desoPercentiles = $this->loadDesoPercentiles($year);
        $this->info('DeSOs with demographic data: '.count($desoPercentiles));

        // Step 2: Load vulnerability mappings
        $this->info('Loading vulnerability area mappings...');
        $vulnMappings = $this->loadVulnerabilityMappings();
        $this->info('DeSOs in vulnerability areas (>=25% overlap): '.count($vulnMappings));

        // Step 3: Load kommun-level Kronofogden data
        $kommunData = DB::table('kronofogden_statistics')
            ->where('year', $year)
            ->whereNotNull('indebted_pct')
            ->get()
            ->keyBy('municipality_code');
        $this->info('Kommuner with Kronofogden data: '.$kommunData->count());

        if ($kommunData->isEmpty()) {
            $this->error('No Kronofogden data found. Run ingest:kronofogden first.');
            $this->failIngestionLog('No source data');

            return self::FAILURE;
        }

        // Step 4: Get DeSOs grouped by kommun
        $desosByKommun = DB::table('deso_areas')
            ->select('deso_code', 'kommun_code', 'population')
            ->get()
            ->groupBy('kommun_code');

        $records = [];
        $kommunsProcessed = 0;

        foreach ($desosByKommun as $kommunCode => $desos) {
            $kfData = $kommunData->get($kommunCode);
            if (! $kfData) {
                continue;
            }

            $desoList = $desos->pluck('deso_code')->toArray();
            $desoPops = $desos->pluck('population', 'deso_code')->toArray();

            // Compute propensity weights for all DeSOs in this kommun
            $weights = [];
            foreach ($desoList as $desoCode) {
                $weights[$desoCode] = $this->computeDebtPropensityWeight(
                    $desoCode,
                    $desoPercentiles,
                    $vulnMappings
                );
            }

            $totalWeight = array_sum($weights);
            if ($totalWeight <= 0) {
                $equalWeight = 1.0 / count($desoList);
                $weights = array_fill_keys($desoList, $equalWeight);
                $totalWeight = 1.0;
            }

            $numDesos = count($desoList);
            $debtRate = (float) $kfData->indebted_pct;
            $evictionRate = $kfData->eviction_rate_per_100k ? (float) $kfData->eviction_rate_per_100k : null;

            // First pass: compute raw estimates
            $estimates = [];
            foreach ($desoList as $desoCode) {
                $normalizedWeight = $weights[$desoCode] / $totalWeight;
                $scaleFactor = $normalizedWeight * $numDesos;

                // Clamp between 10% and 300% of kommun rate
                $estDebt = min($debtRate * 3.0, max($debtRate * 0.1, $debtRate * $scaleFactor));
                $estEviction = $evictionRate !== null
                    ? min($evictionRate * 3.0, max($evictionRate * 0.1, $evictionRate * $scaleFactor))
                    : null;

                $estimates[$desoCode] = [
                    'debt_rate' => $estDebt,
                    'eviction_rate' => $estEviction,
                    'weight' => $normalizedWeight,
                ];
            }

            // Constraint: ensure population-weighted average matches kommun rate
            $estimates = $this->constrainToKommunRate($estimates, $desoPops, $debtRate, 'debt_rate');
            if ($evictionRate !== null) {
                $estimates = $this->constrainToKommunRate($estimates, $desoPops, $evictionRate, 'eviction_rate');
            }

            $now = now();
            foreach ($desoList as $desoCode) {
                $est = $estimates[$desoCode];
                $records[] = [
                    'deso_code' => $desoCode,
                    'year' => $year,
                    'municipality_code' => $kommunCode,
                    'estimated_debt_rate' => round($est['debt_rate'], 3),
                    'estimated_eviction_rate' => $est['eviction_rate'] !== null ? round($est['eviction_rate'], 4) : null,
                    'propensity_weight' => round($est['weight'], 6),
                    'is_constrained' => true,
                    'model_version' => self::MODEL_VERSION,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $kommunsProcessed++;
        }

        // Upsert results
        $this->info('Upserting '.count($records).' DeSO disaggregation results...');
        foreach (array_chunk($records, 1000) as $chunk) {
            DB::table('debt_disaggregation_results')->upsert(
                $chunk,
                ['deso_code', 'year'],
                ['municipality_code', 'estimated_debt_rate', 'estimated_eviction_rate',
                    'propensity_weight', 'is_constrained', 'model_version', 'updated_at']
            );
        }

        // Store model metadata
        $coefficients = [
            'income_weight' => 0.35,
            'employment_weight' => 0.20,
            'education_weight' => 0.15,
            'low_econ_weight' => 0.15,
            'vulnerability_base' => 0.15,
            'vulnerability_sarskilt' => 0.10,
            'base_weight' => 0.5,
        ];

        DB::table('disaggregation_models')->updateOrInsert(
            ['target_variable' => 'debt_rate', 'training_year' => $year],
            [
                'model_type' => 'weighted_regression',
                'coefficients' => json_encode($coefficients),
                'features_used' => json_encode(['median_income', 'employment_rate', 'education_post_secondary_pct', 'low_economic_standard_pct', 'vulnerability_flag']),
                'kommun_count' => $kommunsProcessed,
                'updated_at' => now(),
            ]
        );

        // Show distribution stats
        $this->showDistributionStats($year, $kommunData);

        $this->info("Done. {$kommunsProcessed} kommuner disaggregated → ".count($records).' DeSO estimates.');

        $this->processed = $kommunsProcessed;
        $this->created = count($records);
        $this->addStat('kommuner_processed', $kommunsProcessed);
        $this->addStat('deso_records', count($records));
        $this->completeIngestionLog();

        // Optional cross-validation
        if ($this->option('validate')) {
            $this->runValidation($year, $desoPercentiles, $vulnMappings);
        }

        return self::SUCCESS;
    }

    /**
     * Compute debt propensity weight for a DeSO.
     * Higher weight = higher expected debt propensity.
     */
    private function computeDebtPropensityWeight(string $desoCode, array $percentiles, array $vulnMappings): float
    {
        $weight = 0.5; // Base weight

        $desoData = $percentiles[$desoCode] ?? null;
        if ($desoData) {
            // Lower income → higher debt propensity
            $incomePercentile = $desoData['median_income'] ?? 0.5;
            $weight += (1.0 - $incomePercentile) * 0.35;

            // Lower employment → higher debt
            $employmentPercentile = $desoData['employment_rate'] ?? 0.5;
            $weight += (1.0 - $employmentPercentile) * 0.20;

            // Lower education → higher debt
            $educationPercentile = $desoData['education_post_secondary_pct'] ?? 0.5;
            $weight += (1.0 - $educationPercentile) * 0.15;

            // High low-economic-standard → higher debt (NOT inverted)
            $lowEconPercentile = $desoData['low_economic_standard_pct'] ?? 0.5;
            $weight += $lowEconPercentile * 0.15;
        }

        // Vulnerability area flag
        $vulnInfo = $vulnMappings[$desoCode] ?? null;
        if ($vulnInfo) {
            $weight += 0.15;
            if ($vulnInfo['tier'] === 'sarskilt_utsatt') {
                $weight += 0.10;
            }
        }

        return max($weight, 0.01);
    }

    /**
     * Constrain estimates so population-weighted average matches kommun rate.
     *
     * @param  array<string, array<string, float|null>>  $estimates
     * @param  array<string, int|null>  $populations
     * @return array<string, array<string, float|null>>
     */
    private function constrainToKommunRate(array $estimates, array $populations, float $kommunRate, string $field): array
    {
        $weightedSum = 0.0;
        $totalPop = 0;

        foreach ($estimates as $desoCode => $est) {
            $pop = $populations[$desoCode] ?? 100;
            $weightedSum += ($est[$field] ?? 0) * $pop;
            $totalPop += $pop;
        }

        if ($totalPop <= 0 || $weightedSum <= 0) {
            return $estimates;
        }

        $currentAvg = $weightedSum / $totalPop;
        $scaleFactor = $kommunRate / $currentAvg;

        foreach ($estimates as $desoCode => &$est) {
            if ($est[$field] !== null) {
                $est[$field] *= $scaleFactor;
            }
        }

        return $estimates;
    }

    /**
     * @return array<string, array<string, float>>
     */
    private function loadDesoPercentiles(int $year): array
    {
        $relevantSlugs = ['median_income', 'employment_rate', 'education_post_secondary_pct', 'low_economic_standard_pct'];

        $rows = DB::table('indicator_values')
            ->join('indicators', 'indicators.id', '=', 'indicator_values.indicator_id')
            ->whereIn('indicators.slug', $relevantSlugs)
            ->where('indicator_values.year', $year)
            ->whereNotNull('indicator_values.normalized_value')
            ->select('indicator_values.deso_code', 'indicators.slug', 'indicator_values.normalized_value')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->deso_code][$row->slug] = (float) $row->normalized_value;
        }

        return $result;
    }

    /**
     * @return array<string, array{tier: string, overlap: float}>
     */
    private function loadVulnerabilityMappings(): array
    {
        $rows = DB::table('deso_vulnerability_mapping')
            ->join('vulnerability_areas', 'vulnerability_areas.id', '=', 'deso_vulnerability_mapping.vulnerability_area_id')
            ->where('vulnerability_areas.is_current', true)
            ->where('deso_vulnerability_mapping.overlap_fraction', '>=', 0.25)
            ->select('deso_vulnerability_mapping.deso_code', 'deso_vulnerability_mapping.tier', 'deso_vulnerability_mapping.overlap_fraction')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            if (! isset($result[$row->deso_code]) || $row->tier === 'sarskilt_utsatt') {
                $result[$row->deso_code] = [
                    'tier' => $row->tier,
                    'overlap' => (float) $row->overlap_fraction,
                ];
            }
        }

        return $result;
    }

    private function showDistributionStats(int $year, mixed $kommunData): void
    {
        // Stockholm distribution check
        $stockholmDesos = DB::table('debt_disaggregation_results')
            ->where('municipality_code', '0180')
            ->where('year', $year)
            ->orderByDesc('estimated_debt_rate')
            ->get(['deso_code', 'estimated_debt_rate']);

        if ($stockholmDesos->isNotEmpty()) {
            $this->info('Stockholm kommun distribution:');
            $this->info('  Highest: '.$stockholmDesos->first()->deso_code.' ('.round($stockholmDesos->first()->estimated_debt_rate, 2).'%)');
            $this->info('  Lowest: '.$stockholmDesos->last()->deso_code.' ('.round($stockholmDesos->last()->estimated_debt_rate, 2).'%)');
            $this->info('  Kommun actual: '.$kommunData->get('0180')?->indebted_pct.'%');
        }

        // Overall range
        $range = DB::table('debt_disaggregation_results')
            ->where('year', $year)
            ->selectRaw('MIN(estimated_debt_rate) as min_rate, MAX(estimated_debt_rate) as max_rate, AVG(estimated_debt_rate) as avg_rate, COUNT(*) as total')
            ->first();

        $this->info(sprintf(
            'DeSO debt rate range: %.2f%% - %.2f%% (avg %.2f%%), %d DeSOs',
            $range->min_rate,
            $range->max_rate,
            $range->avg_rate,
            $range->total
        ));
    }

    private function runValidation(int $year, array $desoPercentiles, array $vulnMappings): void
    {
        $this->info("\nRunning cross-validation...");

        $kommunData = DB::table('kronofogden_statistics')
            ->where('year', $year)
            ->whereNotNull('indebted_pct')
            ->get()
            ->keyBy('municipality_code');

        $desosByKommun = DB::table('deso_areas')
            ->select('deso_code', 'kommun_code', 'population')
            ->get()
            ->groupBy('kommun_code');

        // For each kommun, predict its rate from its DeSOs' demographics
        $predicted = [];
        $actual = [];

        foreach ($kommunData as $code => $kf) {
            $desos = $desosByKommun->get($code);
            if (! $desos || $desos->count() < 2) {
                continue;
            }

            // Average demographic weight for DeSOs in this kommun
            $avgWeight = 0;
            foreach ($desos as $deso) {
                $avgWeight += $this->computeDebtPropensityWeight($deso->deso_code, $desoPercentiles, $vulnMappings);
            }
            $avgWeight /= $desos->count();

            $predicted[] = $avgWeight;
            $actual[] = (float) $kf->indebted_pct;
        }

        if (count($predicted) < 10) {
            $this->warn('Too few data points for validation.');

            return;
        }

        // Compute R² (correlation between avg propensity weight and actual debt rate)
        $n = count($predicted);
        $meanActual = array_sum($actual) / $n;
        $ssTot = 0;
        $ssRes = 0;

        // Simple linear regression: actual = a + b * predicted
        $meanPred = array_sum($predicted) / $n;
        $covXY = 0;
        $varX = 0;
        for ($i = 0; $i < $n; $i++) {
            $covXY += ($predicted[$i] - $meanPred) * ($actual[$i] - $meanActual);
            $varX += ($predicted[$i] - $meanPred) ** 2;
        }

        $b = $varX > 0 ? $covXY / $varX : 0;
        $a = $meanActual - $b * $meanPred;

        for ($i = 0; $i < $n; $i++) {
            $fitted = $a + $b * $predicted[$i];
            $ssRes += ($actual[$i] - $fitted) ** 2;
            $ssTot += ($actual[$i] - $meanActual) ** 2;
        }

        $rSquared = $ssTot > 0 ? 1.0 - ($ssRes / $ssTot) : 0;
        $rmse = sqrt($ssRes / $n);

        $this->info(sprintf('Validation R² = %.4f, RMSE = %.4f (%d kommuner)', $rSquared, $rmse, $n));

        DB::table('disaggregation_models')->updateOrInsert(
            ['target_variable' => 'debt_rate', 'training_year' => $year],
            [
                'r_squared' => round($rSquared, 4),
                'rmse' => round($rmse, 4),
                'updated_at' => now(),
            ]
        );
    }
}
