<?php

namespace App\Services;

use App\Models\SmoothingConfig;
use Illuminate\Support\Facades\DB;

class SpatialSmoothingService
{
    /**
     * Apply spatial smoothing to H3 scores.
     *
     * For each hex, the smoothed score is:
     *   smoothed = self_weight * score(hex) + neighbor_weight * avg(neighbors)
     *
     * Where neighbors are found via h3_grid_disk at distance 1..k_rings,
     * with optional decay by ring distance.
     */
    public function smooth(int $year, int $resolution, SmoothingConfig $config): int
    {
        if ($config->k_rings === 0 || (float) $config->neighbor_weight === 0.0) {
            // No smoothing — just copy raw to smoothed
            return DB::update('
                UPDATE h3_scores
                SET score_smoothed = score_raw,
                    smoothing_factor = 0
                WHERE year = ? AND resolution = ? AND score_raw IS NOT NULL
            ', [$year, $resolution]);
        }

        if ($config->k_rings === 1) {
            return $this->smoothKRing1($year, $resolution, $config);
        }

        return $this->smoothKRingN($year, $resolution, $config);
    }

    /**
     * Optimized smoothing for k=1 (immediate neighbors only, uniform weight).
     */
    private function smoothKRing1(int $year, int $resolution, SmoothingConfig $config): int
    {
        $selfWeight = (float) $config->self_weight;
        $neighborWeight = (float) $config->neighbor_weight;

        return DB::update('
            UPDATE h3_scores target
            SET score_smoothed = ROUND((
                ? * target.score_raw +
                ? * COALESCE(neighbors.avg_score, target.score_raw)
            )::numeric, 2),
            smoothing_factor = ?
            FROM (
                SELECT
                    center.h3_index,
                    AVG(neighbor_scores.score_raw) AS avg_score
                FROM h3_scores center
                CROSS JOIN LATERAL unnest(
                    (SELECT array_agg(ring::text)
                     FROM h3_grid_disk(center.h3_index::h3index, 1) ring)
                ) AS neighbor_h3(h3_text)
                LEFT JOIN h3_scores neighbor_scores
                    ON neighbor_scores.h3_index = neighbor_h3.h3_text
                    AND neighbor_scores.year = center.year
                    AND neighbor_scores.resolution = center.resolution
                    AND neighbor_scores.h3_index != center.h3_index
                WHERE center.year = ?
                  AND center.resolution = ?
                  AND center.score_raw IS NOT NULL
                GROUP BY center.h3_index
            ) neighbors
            WHERE target.h3_index = neighbors.h3_index
              AND target.year = ?
              AND target.resolution = ?
        ', [$selfWeight, $neighborWeight, $neighborWeight, $year, $resolution, $year, $resolution]);
    }

    /**
     * General smoothing for k>1 rings with decay function.
     */
    private function smoothKRingN(int $year, int $resolution, SmoothingConfig $config): int
    {
        $selfWeight = (float) $config->self_weight;
        $neighborWeight = (float) $config->neighbor_weight;
        $kRings = $config->k_rings;

        // For gaussian decay: weight = exp(-d²/2) where d is ring distance / k_rings
        // For linear decay: weight = 1 - d/k_rings
        $decayExpr = match ($config->decay_function) {
            'gaussian' => 'EXP(-POWER(ring_dist::float / ?, 2) / 2)',
            'linear' => '1.0 - ring_dist::float / (? + 1)',
            default => '1.0 / ?', // uniform but still needs parameter
        };

        $decayParam = match ($config->decay_function) {
            'gaussian' => $kRings,
            'linear' => $kRings,
            default => $kRings,
        };

        return DB::update("
            UPDATE h3_scores target
            SET score_smoothed = ROUND((
                ? * target.score_raw +
                ? * COALESCE(neighbors.weighted_avg, target.score_raw)
            )::numeric, 2),
            smoothing_factor = ?
            FROM (
                SELECT
                    center.h3_index,
                    SUM(ns.score_raw * ({$decayExpr})) / NULLIF(SUM({$decayExpr}), 0) AS weighted_avg
                FROM h3_scores center
                CROSS JOIN LATERAL (
                    SELECT
                        ring::text AS h3_text,
                        h3_grid_distance(center.h3_index::h3index, ring) AS ring_dist
                    FROM h3_grid_disk(center.h3_index::h3index, ?) ring
                    WHERE ring::text != center.h3_index
                ) AS neighbor_h3
                LEFT JOIN h3_scores ns
                    ON ns.h3_index = neighbor_h3.h3_text
                    AND ns.year = center.year
                    AND ns.resolution = center.resolution
                WHERE center.year = ?
                  AND center.resolution = ?
                  AND center.score_raw IS NOT NULL
                  AND ns.score_raw IS NOT NULL
                GROUP BY center.h3_index
            ) neighbors
            WHERE target.h3_index = neighbors.h3_index
              AND target.year = ?
              AND target.resolution = ?
        ", [$selfWeight, $neighborWeight, $neighborWeight, $decayParam, $decayParam, $kRings, $year, $resolution, $year, $resolution]);
    }
}
