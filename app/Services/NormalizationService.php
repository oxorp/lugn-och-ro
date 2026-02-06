<?php

namespace App\Services;

use App\Models\Indicator;
use Illuminate\Support\Facades\DB;

class NormalizationService
{
    /**
     * Normalize all active indicators for a given year.
     */
    public function normalizeAll(int $year): int
    {
        $indicators = Indicator::query()
            ->where('is_active', true)
            ->get();

        $total = 0;
        foreach ($indicators as $indicator) {
            $total += $this->normalizeIndicator($indicator, $year);
        }

        return $total;
    }

    /**
     * Normalize a single indicator for a given year using its configured method.
     */
    public function normalizeIndicator(Indicator $indicator, int $year): int
    {
        return match ($indicator->normalization) {
            'rank_percentile' => $this->rankPercentile($indicator, $year),
            'min_max' => $this->minMax($indicator, $year),
            'z_score' => $this->zScore($indicator, $year),
            default => $this->rankPercentile($indicator, $year),
        };
    }

    /**
     * Percentile rank: each value gets its rank / total count (0.0 to 1.0).
     * Uses PERCENT_RANK() which handles ties with average rank.
     */
    private function rankPercentile(Indicator $indicator, int $year): int
    {
        return DB::update('
            UPDATE indicator_values iv
            SET normalized_value = sub.percentile,
                updated_at = NOW()
            FROM (
                SELECT id,
                       PERCENT_RANK() OVER (ORDER BY raw_value) as percentile
                FROM indicator_values
                WHERE indicator_id = ? AND year = ? AND raw_value IS NOT NULL
            ) sub
            WHERE iv.id = sub.id
        ', [$indicator->id, $year]);
    }

    /**
     * Min-max normalization: (value - min) / (max - min).
     */
    private function minMax(Indicator $indicator, int $year): int
    {
        return DB::update('
            UPDATE indicator_values iv
            SET normalized_value = sub.normalized,
                updated_at = NOW()
            FROM (
                SELECT id,
                       CASE WHEN max_val = min_val THEN 0.5
                            ELSE (raw_value - min_val) / (max_val - min_val)
                       END as normalized
                FROM indicator_values,
                     (SELECT MIN(raw_value) as min_val, MAX(raw_value) as max_val
                      FROM indicator_values
                      WHERE indicator_id = ? AND year = ? AND raw_value IS NOT NULL) bounds
                WHERE indicator_id = ? AND year = ? AND raw_value IS NOT NULL
            ) sub
            WHERE iv.id = sub.id
        ', [$indicator->id, $year, $indicator->id, $year]);
    }

    /**
     * Z-score: (value - mean) / stddev, then scaled to 0-1 via CDF approximation.
     */
    private function zScore(Indicator $indicator, int $year): int
    {
        return DB::update('
            UPDATE indicator_values iv
            SET normalized_value = sub.normalized,
                updated_at = NOW()
            FROM (
                SELECT id,
                       GREATEST(0, LEAST(1,
                           0.5 + 0.5 * SIGN(z) * SQRT(1 - EXP(-2 * z * z / 3.14159))
                       )) as normalized
                FROM (
                    SELECT id,
                           CASE WHEN stddev_val = 0 THEN 0
                                ELSE (raw_value - mean_val) / stddev_val
                           END as z
                    FROM indicator_values,
                         (SELECT AVG(raw_value) as mean_val,
                                 STDDEV_POP(raw_value) as stddev_val
                          FROM indicator_values
                          WHERE indicator_id = ? AND year = ? AND raw_value IS NOT NULL) stats
                    WHERE indicator_id = ? AND year = ? AND raw_value IS NOT NULL
                ) zscores
            ) sub
            WHERE iv.id = sub.id
        ', [$indicator->id, $year, $indicator->id, $year]);
    }
}
