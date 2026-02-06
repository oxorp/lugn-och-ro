<?php

namespace App\Services;

use App\Models\Indicator;
use Illuminate\Support\Facades\DB;

class ScoringService
{
    /**
     * Compute composite scores for all DeSOs for a given year.
     */
    public function computeScores(int $year): int
    {
        $indicators = Indicator::query()
            ->where('is_active', true)
            ->where('weight', '>', 0)
            ->get();

        if ($indicators->isEmpty()) {
            return 0;
        }

        $totalWeight = $indicators->sum('weight');

        // Fetch all normalized values for scoring indicators at the given year.
        // For indicators from different years, also check the closest available year.
        $indicatorData = $this->fetchIndicatorData($indicators, $year);

        // Get all unique DeSO codes that have at least one indicator value
        $desoCodes = array_unique(array_merge(
            ...array_values(array_map('array_keys', $indicatorData))
        ));

        $scores = [];
        $now = now();

        foreach ($desoCodes as $desoCode) {
            $weightedSum = 0;
            $availableWeight = 0;
            $factorScores = [];
            $topPositive = [];
            $topNegative = [];

            foreach ($indicators as $indicator) {
                $normalizedValue = $indicatorData[$indicator->id][$desoCode] ?? null;
                if ($normalizedValue === null) {
                    continue;
                }

                $directedValue = match ($indicator->direction) {
                    'positive' => (float) $normalizedValue,
                    'negative' => 1.0 - (float) $normalizedValue,
                    default => null,
                };

                if ($directedValue === null) {
                    continue;
                }

                $weightedSum += $indicator->weight * $directedValue;
                $availableWeight += (float) $indicator->weight;
                $factorScores[$indicator->slug] = round($directedValue, 4);

                if ($directedValue >= 0.7) {
                    $topPositive[] = $indicator->slug;
                } elseif ($directedValue <= 0.3) {
                    $topNegative[] = $indicator->slug;
                }
            }

            if ($availableWeight === 0.0) {
                continue;
            }

            $score = round(($weightedSum / $availableWeight) * 100, 2);

            $scores[] = [
                'deso_code' => $desoCode,
                'year' => $year,
                'score' => $score,
                'trend_1y' => null,
                'trend_3y' => null,
                'factor_scores' => json_encode($factorScores),
                'top_positive' => json_encode($topPositive),
                'top_negative' => json_encode($topNegative),
                'computed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Bulk upsert
        foreach (array_chunk($scores, 1000) as $chunk) {
            DB::table('composite_scores')->upsert(
                $chunk,
                ['deso_code', 'year'],
                ['score', 'trend_1y', 'trend_3y', 'factor_scores', 'top_positive', 'top_negative', 'computed_at', 'updated_at']
            );
        }

        // Compute trends if previous year data exists
        $this->computeTrends($year);

        return count($scores);
    }

    /**
     * Fetch all normalized values for scoring indicators, finding closest available year.
     *
     * @return array<int, array<string, float>> indicator_id => [deso_code => normalized_value]
     */
    private function fetchIndicatorData(\Illuminate\Database\Eloquent\Collection $indicators, int $year): array
    {
        $data = [];

        foreach ($indicators as $indicator) {
            // Try exact year first, then fall back to closest available year
            $values = DB::table('indicator_values')
                ->where('indicator_id', $indicator->id)
                ->where('year', $year)
                ->whereNotNull('normalized_value')
                ->pluck('normalized_value', 'deso_code')
                ->toArray();

            if (empty($values)) {
                // Find closest year with data
                $closestYear = DB::table('indicator_values')
                    ->where('indicator_id', $indicator->id)
                    ->whereNotNull('normalized_value')
                    ->selectRaw('year, ABS(year - ?) as distance', [$year])
                    ->orderBy('distance')
                    ->limit(1)
                    ->value('year');

                if ($closestYear) {
                    $values = DB::table('indicator_values')
                        ->where('indicator_id', $indicator->id)
                        ->where('year', $closestYear)
                        ->whereNotNull('normalized_value')
                        ->pluck('normalized_value', 'deso_code')
                        ->toArray();
                }
            }

            $data[$indicator->id] = $values;
        }

        return $data;
    }

    private function computeTrends(int $year): void
    {
        // 1-year trend
        DB::update('
            UPDATE composite_scores cs
            SET trend_1y = cs.score - prev.score
            FROM composite_scores prev
            WHERE cs.year = ?
              AND prev.deso_code = cs.deso_code
              AND prev.year = ?
        ', [$year, $year - 1]);

        // 3-year trend
        DB::update('
            UPDATE composite_scores cs
            SET trend_3y = cs.score - prev.score
            FROM composite_scores prev
            WHERE cs.year = ?
              AND prev.deso_code = cs.deso_code
              AND prev.year = ?
        ', [$year, $year - 3]);
    }
}
