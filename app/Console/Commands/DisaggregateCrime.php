<?php

namespace App\Console\Commands;

use App\Models\IngestionLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DisaggregateCrime extends Command
{
    protected $signature = 'disaggregate:crime
        {--year=2024 : Year for the data}';

    protected $description = 'Disaggregate kommun-level crime rates to DeSO using demographic-weighted model';

    public function handle(): int
    {
        $year = (int) $this->option('year');

        $log = IngestionLog::query()->create([
            'source' => 'bra_disaggregated',
            'command' => 'disaggregate:crime',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => ['year' => $year],
        ]);

        $this->info("Disaggregating crime data to DeSO level for year {$year}...");

        // Step 1: Get crime indicators (will be created via seeder/migration)
        $crimeIndicators = $this->ensureCrimeIndicators();

        // Step 2: Load DeSO-level demographic percentiles
        $this->info('Loading DeSO demographic percentiles...');
        $desoPercentiles = $this->loadDesoPercentiles($year);
        $this->info('DeSOs with demographic data: '.count($desoPercentiles));

        // Step 3: Load vulnerability mappings
        $this->info('Loading vulnerability area mappings...');
        $vulnMappings = $this->loadVulnerabilityMappings();
        $this->info('DeSOs in vulnerability areas (>=25% overlap): '.count($vulnMappings));

        // Step 4: Load kommun-level crime rates
        $this->info('Loading kommun-level crime rates...');
        $kommunCrime = DB::table('crime_statistics')
            ->where('year', $year)
            ->get()
            ->groupBy('municipality_code');
        $this->info('Kommuner with crime data: '.$kommunCrime->count());

        // Step 5: Get DeSOs grouped by kommun
        $desosByKommun = DB::table('deso_areas')
            ->select('deso_code', 'kommun_code')
            ->get()
            ->groupBy('kommun_code');

        $records = [];
        $kommunsProcessed = 0;

        foreach ($desosByKommun as $kommunCode => $desos) {
            $crimeData = $kommunCrime->get($kommunCode);
            if (! $crimeData) {
                continue;
            }

            $crimeByCategory = $crimeData->keyBy('crime_category');
            $desoList = $desos->pluck('deso_code')->toArray();

            // Compute weights for all DeSOs in this kommun
            $weights = [];
            foreach ($desoList as $desoCode) {
                $weights[$desoCode] = $this->computePropensityWeight(
                    $desoCode,
                    $desoPercentiles,
                    $vulnMappings
                );
            }

            // Normalize weights to sum to 1.0
            $totalWeight = array_sum($weights);
            if ($totalWeight <= 0) {
                // Equal distribution fallback
                $equalWeight = 1.0 / count($desoList);
                $weights = array_fill_keys($desoList, $equalWeight);
                $totalWeight = 1.0;
            }

            $numDesos = count($desoList);

            // Disaggregate crime rates
            // violent = person + robbery + sexual
            $violentRate = ($crimeByCategory->get('crime_person')->rate_per_100k ?? 0)
                + ($crimeByCategory->get('crime_robbery')->rate_per_100k ?? 0)
                + ($crimeByCategory->get('crime_sexual')->rate_per_100k ?? 0);

            // property = theft + damage
            $propertyRate = ($crimeByCategory->get('crime_theft')->rate_per_100k ?? 0)
                + ($crimeByCategory->get('crime_damage')->rate_per_100k ?? 0);

            $totalRate = $crimeByCategory->get('crime_total')->rate_per_100k ?? 0;

            foreach ($desoList as $desoCode) {
                $normalizedWeight = $weights[$desoCode] / $totalWeight;

                // Scale: DeSO rate = kommun rate × (DeSO weight / avg weight) = kommun rate × (normalized_weight × num_desos)
                $scaleFactor = $normalizedWeight * $numDesos;

                $records[] = $this->makeRecord($desoCode, $crimeIndicators['crime_violent_rate'], $year, $violentRate * $scaleFactor);
                $records[] = $this->makeRecord($desoCode, $crimeIndicators['crime_property_rate'], $year, $propertyRate * $scaleFactor);
                $records[] = $this->makeRecord($desoCode, $crimeIndicators['crime_total_rate'], $year, $totalRate * $scaleFactor);
            }

            $kommunsProcessed++;
        }

        // Step 6: Add vulnerability flag indicator values
        $vulnFlagIndicator = $crimeIndicators['vulnerability_flag'] ?? null;
        if ($vulnFlagIndicator) {
            $this->info('Setting vulnerability flag indicator values...');
            $allDesoCodes = DB::table('deso_areas')->pluck('deso_code');

            foreach ($allDesoCodes as $desoCode) {
                $vulnInfo = $vulnMappings[$desoCode] ?? null;
                $rawValue = 0;
                if ($vulnInfo) {
                    $rawValue = $vulnInfo['tier'] === 'sarskilt_utsatt' ? 2 : 1;
                }

                $records[] = $this->makeRecord($desoCode, $vulnFlagIndicator, $year, $rawValue);
            }
        }

        // Step 7: Add perceived safety from NTU (lan → deso disaggregation)
        $perceivedSafetyIndicator = $crimeIndicators['perceived_safety'] ?? null;
        if ($perceivedSafetyIndicator) {
            $this->info('Disaggregating NTU perceived safety to DeSO...');
            $ntuRecords = $this->disaggregateNtu($year, $perceivedSafetyIndicator, $desoPercentiles, $vulnMappings);
            $records = array_merge($records, $ntuRecords);
        }

        // Step 8: Upsert all records
        $this->info('Upserting '.count($records).' disaggregated indicator values...');

        foreach (array_chunk($records, 1000) as $chunk) {
            DB::table('indicator_values')->upsert(
                $chunk,
                ['deso_code', 'indicator_id', 'year'],
                ['raw_value', 'updated_at']
            );
        }

        $this->info("Done. {$kommunsProcessed} kommuner disaggregated, ".count($records).' indicator value records.');

        $log->update([
            'status' => 'completed',
            'records_processed' => $kommunsProcessed,
            'records_created' => count($records),
            'completed_at' => now(),
            'metadata' => [
                'year' => $year,
                'kommuner_processed' => $kommunsProcessed,
                'total_records' => count($records),
            ],
        ]);

        return self::SUCCESS;
    }

    /**
     * Compute propensity weight for a DeSO based on demographic indicators.
     * Higher weight = higher expected crime propensity.
     */
    private function computePropensityWeight(string $desoCode, array $percentiles, array $vulnMappings): float
    {
        $weight = 0.5; // Base weight — ensures no DeSO gets zero

        $desoData = $percentiles[$desoCode] ?? null;
        if ($desoData) {
            // Lower income → more crime (inverted percentile)
            $incomePercentile = $desoData['median_income'] ?? 0.5;
            $weight += (1.0 - $incomePercentile) * 0.35;

            // Lower employment → more crime
            $employmentPercentile = $desoData['employment_rate'] ?? 0.5;
            $weight += (1.0 - $employmentPercentile) * 0.20;

            // Lower education → more crime
            $educationPercentile = $desoData['education_post_secondary_pct'] ?? 0.5;
            $weight += (1.0 - $educationPercentile) * 0.15;
        }

        // Vulnerability area flag
        $vulnInfo = $vulnMappings[$desoCode] ?? null;
        if ($vulnInfo) {
            $weight += 0.30; // Utsatt område
            if ($vulnInfo['tier'] === 'sarskilt_utsatt') {
                $weight += 0.20; // Extra for särskilt utsatt
            }
        }

        return max($weight, 0.01);
    }

    /**
     * Load normalized DeSO percentiles for income, employment, education.
     *
     * @return array<string, array<string, float>> DeSO code => [indicator_slug => percentile]
     */
    private function loadDesoPercentiles(int $year): array
    {
        $relevantSlugs = ['median_income', 'employment_rate', 'education_post_secondary_pct'];

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
     * Load vulnerability mappings with >=25% overlap.
     *
     * @return array<string, array{tier: string, overlap: float}> DeSO code => info
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
            // Keep highest tier per DeSO
            if (! isset($result[$row->deso_code]) || $row->tier === 'sarskilt_utsatt') {
                $result[$row->deso_code] = [
                    'tier' => $row->tier,
                    'overlap' => (float) $row->overlap_fraction,
                ];
            }
        }

        return $result;
    }

    /**
     * Disaggregate NTU perceived safety from län to DeSO.
     *
     * @return list<array<string, mixed>>
     */
    private function disaggregateNtu(int $year, int $indicatorId, array $desoPercentiles, array $vulnMappings): array
    {
        // Get NTU unsafe_night data by län for latest available year
        $ntuData = DB::table('ntu_survey_data')
            ->where('area_type', 'lan')
            ->where('indicator_slug', 'ntu_unsafe_night')
            ->where('survey_year', '<=', $year + 1)
            ->orderByDesc('survey_year')
            ->get()
            ->groupBy('area_code')
            ->map(fn ($group) => $group->first());

        if ($ntuData->isEmpty()) {
            $this->warn('No NTU data found for disaggregation.');

            return [];
        }

        $this->info('NTU data from '.($ntuData->first()->survey_year ?? '?').' for '.count($ntuData).' län.');

        // Get DeSOs grouped by län
        $desosByLan = DB::table('deso_areas')
            ->select('deso_code', 'lan_code')
            ->get()
            ->groupBy('lan_code');

        $records = [];

        foreach ($desosByLan as $lanCode => $desos) {
            $ntuRow = $ntuData->get($lanCode);
            if (! $ntuRow || $ntuRow->value === null) {
                continue;
            }

            $otrygghetsPercent = (float) $ntuRow->value;
            // Convert from "% unsafe" to "% safe" (our indicator is perceived_safety, positive)
            $lanSafetyPercent = 100.0 - $otrygghetsPercent;

            $desoList = $desos->pluck('deso_code')->toArray();

            // Compute weights (similar to crime disaggregation but inverted — safer areas get higher safety)
            $weights = [];
            foreach ($desoList as $desoCode) {
                $weight = 0.5;
                $desoData = $desoPercentiles[$desoCode] ?? null;
                if ($desoData) {
                    $weight += ($desoData['median_income'] ?? 0.5) * 0.35;
                    $weight += ($desoData['employment_rate'] ?? 0.5) * 0.20;
                    $weight += ($desoData['education_post_secondary_pct'] ?? 0.5) * 0.15;
                }

                $vulnInfo = $vulnMappings[$desoCode] ?? null;
                if ($vulnInfo) {
                    $weight -= 0.30;
                    if ($vulnInfo['tier'] === 'sarskilt_utsatt') {
                        $weight -= 0.20;
                    }
                }

                $weights[$desoCode] = max($weight, 0.01);
            }

            $totalWeight = array_sum($weights);
            $numDesos = count($desoList);

            foreach ($desoList as $desoCode) {
                $normalizedWeight = $weights[$desoCode] / $totalWeight;
                $scaleFactor = $normalizedWeight * $numDesos;

                // Clamp between 0 and 100
                $desoSafety = min(100, max(0, $lanSafetyPercent * $scaleFactor));
                $records[] = $this->makeRecord($desoCode, $indicatorId, $year, round($desoSafety, 2));
            }
        }

        return $records;
    }

    /**
     * @return array<string, mixed>
     */
    private function makeRecord(string $desoCode, int $indicatorId, int $year, float $rawValue): array
    {
        return [
            'deso_code' => $desoCode,
            'indicator_id' => $indicatorId,
            'year' => $year,
            'raw_value' => round($rawValue, 4),
            'normalized_value' => null, // Will be set by normalize:indicators
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @return array<string, int> slug => indicator ID
     */
    private function ensureCrimeIndicators(): array
    {
        $indicators = DB::table('indicators')
            ->whereIn('slug', ['crime_violent_rate', 'crime_property_rate', 'crime_total_rate', 'perceived_safety', 'vulnerability_flag'])
            ->pluck('id', 'slug')
            ->toArray();

        if (count($indicators) < 5) {
            $this->warn('Not all crime indicators exist yet. Creating missing ones...');
            $this->call('db:seed', ['--class' => 'CrimeIndicatorSeeder', '--no-interaction' => true]);

            $indicators = DB::table('indicators')
                ->whereIn('slug', ['crime_violent_rate', 'crime_property_rate', 'crime_total_rate', 'perceived_safety', 'vulnerability_flag'])
                ->pluck('id', 'slug')
                ->toArray();
        }

        return $indicators;
    }
}
