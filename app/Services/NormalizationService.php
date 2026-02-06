<?php

namespace App\Services;

use App\Models\Indicator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        if ($indicator->normalization_scope === 'urbanity_stratified') {
            return $this->normalizeStratified($indicator, $year);
        }

        return match ($indicator->normalization) {
            'rank_percentile' => $this->rankPercentile($indicator, $year),
            'min_max' => $this->minMax($indicator, $year),
            'z_score' => $this->zScore($indicator, $year),
            default => $this->rankPercentile($indicator, $year),
        };
    }

    /**
     * Stratified normalization: rank within each urbanity tier separately.
     * Falls back to national ranking for tiers with fewer than 30 data points
     * or for DeSOs without an urbanity classification.
     */
    private function normalizeStratified(Indicator $indicator, int $year): int
    {
        $minTierSize = 30;

        // Check tier sizes for this indicator
        $tierCounts = DB::table('indicator_values')
            ->join('deso_areas', 'deso_areas.deso_code', '=', 'indicator_values.deso_code')
            ->where('indicator_values.indicator_id', $indicator->id)
            ->where('indicator_values.year', $year)
            ->whereNotNull('indicator_values.raw_value')
            ->whereNotNull('deso_areas.urbanity_tier')
            ->selectRaw('deso_areas.urbanity_tier, COUNT(*) as cnt')
            ->groupBy('deso_areas.urbanity_tier')
            ->pluck('cnt', 'urbanity_tier');

        $validTiers = $tierCounts->filter(fn ($count) => $count >= $minTierSize)->keys()->toArray();
        $smallTiers = $tierCounts->filter(fn ($count) => $count < $minTierSize)->keys()->toArray();

        if (! empty($smallTiers)) {
            Log::warning("Stratified normalization: tiers with < {$minTierSize} values for {$indicator->slug}: ".implode(', ', $smallTiers).'. Falling back to national ranking for these tiers.');
        }

        $total = 0;

        // Normalize valid tiers with stratified ranking
        if (! empty($validTiers)) {
            $placeholders = implode(',', array_fill(0, count($validTiers), '?'));
            $total += DB::update("
                UPDATE indicator_values iv
                SET normalized_value = sub.percentile,
                    updated_at = NOW()
                FROM (
                    SELECT iv2.id,
                           PERCENT_RANK() OVER (
                               PARTITION BY da.urbanity_tier
                               ORDER BY iv2.raw_value
                           ) as percentile
                    FROM indicator_values iv2
                    JOIN deso_areas da ON da.deso_code = iv2.deso_code
                    WHERE iv2.indicator_id = ? AND iv2.year = ? AND iv2.raw_value IS NOT NULL
                      AND da.urbanity_tier IN ({$placeholders})
                ) sub
                WHERE iv.id = sub.id
            ", [$indicator->id, $year, ...$validTiers]);
        }

        // Fall back to national ranking for small tiers
        if (! empty($smallTiers)) {
            $placeholders = implode(',', array_fill(0, count($smallTiers), '?'));
            $total += DB::update("
                UPDATE indicator_values iv
                SET normalized_value = sub.percentile,
                    updated_at = NOW()
                FROM (
                    SELECT iv2.id,
                           PERCENT_RANK() OVER (ORDER BY iv2.raw_value) as percentile
                    FROM indicator_values iv2
                    JOIN deso_areas da ON da.deso_code = iv2.deso_code
                    WHERE iv2.indicator_id = ? AND iv2.year = ? AND iv2.raw_value IS NOT NULL
                      AND da.urbanity_tier IN ({$placeholders})
                ) sub
                WHERE iv.id = sub.id
            ", [$indicator->id, $year, ...$smallTiers]);
        }

        // Handle DeSOs without urbanity classification — fall back to national ranking
        $nullTierCount = DB::table('indicator_values')
            ->join('deso_areas', 'deso_areas.deso_code', '=', 'indicator_values.deso_code')
            ->where('indicator_values.indicator_id', $indicator->id)
            ->where('indicator_values.year', $year)
            ->whereNotNull('indicator_values.raw_value')
            ->whereNull('deso_areas.urbanity_tier')
            ->count();

        if ($nullTierCount > 0) {
            Log::warning("Stratified normalization: {$nullTierCount} DeSOs without urbanity_tier for {$indicator->slug}. Using national ranking.");

            $total += DB::update('
                UPDATE indicator_values iv
                SET normalized_value = sub.percentile,
                    updated_at = NOW()
                FROM (
                    SELECT iv2.id,
                           PERCENT_RANK() OVER (ORDER BY iv2.raw_value) as percentile
                    FROM indicator_values iv2
                    JOIN deso_areas da ON da.deso_code = iv2.deso_code
                    WHERE iv2.indicator_id = ? AND iv2.year = ? AND iv2.raw_value IS NOT NULL
                      AND da.urbanity_tier IS NULL
                ) sub
                WHERE iv.id = sub.id
            ', [$indicator->id, $year]);
        }

        return $total;
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
