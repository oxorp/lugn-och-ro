<?php

namespace App\Services;

use App\Models\Indicator;
use App\Models\MethodologyChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TrendService
{
    public function computeTrends(int $baseYear, int $endYear): array
    {
        $stableThreshold = (float) config('scoring.stable_threshold_pct', 3.0);

        $indicators = Indicator::query()
            ->where('is_active', true)
            ->where('direction', '!=', 'neutral')
            ->get();

        $stats = [
            'indicators_processed' => 0,
            'trends_computed' => 0,
            'methodology_breaks' => 0,
        ];

        foreach ($indicators as $indicator) {
            $count = $this->computeIndicatorTrend($indicator, $baseYear, $endYear, $stableThreshold);
            $stats['indicators_processed']++;
            $stats['trends_computed'] += $count;
        }

        return $stats;
    }

    private function computeIndicatorTrend(Indicator $indicator, int $baseYear, int $endYear, float $stableThreshold): int
    {
        // Check for methodology breaks within the trend window
        $hasBreak = MethodologyChange::query()
            ->where('indicator_id', $indicator->id)
            ->where('breaks_trend', true)
            ->whereBetween('year_affected', [$baseYear, $endYear])
            ->exists();

        if ($hasBreak) {
            Log::info("Skipping trend for {$indicator->slug}: methodology break within {$baseYear}-{$endYear}");

            return 0;
        }

        $results = DB::select('
            SELECT
                base.deso_code,
                base.raw_value AS base_value,
                latest.raw_value AS end_value,
                (SELECT COUNT(*) FROM indicator_values iv
                 WHERE iv.deso_code = base.deso_code
                 AND iv.indicator_id = ?
                 AND iv.year BETWEEN ? AND ?
                 AND iv.raw_value IS NOT NULL) AS data_points
            FROM indicator_values base
            JOIN indicator_values latest
                ON latest.deso_code = base.deso_code
                AND latest.indicator_id = base.indicator_id
                AND latest.year = ?
            JOIN deso_areas da ON da.deso_code = base.deso_code
            WHERE base.indicator_id = ?
              AND base.year = ?
              AND base.raw_value IS NOT NULL
              AND latest.raw_value IS NOT NULL
              AND da.trend_eligible = true
        ', [$indicator->id, $baseYear, $endYear, $endYear, $indicator->id, $baseYear]);

        if (count($results) === 0) {
            return 0;
        }

        $expectedPoints = $endYear - $baseYear + 1;
        $now = now()->toDateTimeString();
        $rows = [];

        foreach ($results as $row) {
            $absoluteChange = $row->end_value - $row->base_value;
            $percentChange = $row->base_value != 0
                ? ($absoluteChange / abs($row->base_value)) * 100
                : null;

            $direction = $this->classifyDirection($percentChange, $row->data_points, $stableThreshold);
            $confidence = min(1.0, $row->data_points / $expectedPoints);

            $rows[] = [
                'deso_code' => $row->deso_code,
                'indicator_id' => $indicator->id,
                'base_year' => $baseYear,
                'end_year' => $endYear,
                'data_points' => $row->data_points,
                'absolute_change' => round($absoluteChange, 4),
                'percent_change' => $percentChange !== null ? round($percentChange, 2) : null,
                'direction' => $direction,
                'confidence' => round($confidence, 2),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Bulk upsert
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('indicator_trends')->upsert(
                $chunk,
                ['deso_code', 'indicator_id', 'base_year', 'end_year'],
                ['data_points', 'absolute_change', 'percent_change', 'direction', 'confidence', 'updated_at']
            );
        }

        return count($rows);
    }

    private function classifyDirection(?float $percentChange, int $dataPoints, float $stableThreshold): string
    {
        if ($dataPoints < 2 || $percentChange === null) {
            return 'insufficient';
        }
        if (abs($percentChange) <= $stableThreshold) {
            return 'stable';
        }

        return $percentChange > 0 ? 'rising' : 'falling';
    }
}
