<?php

namespace App\Services;

use App\Models\Indicator;
use App\Models\ScoreVersion;
use Illuminate\Support\Facades\DB;

class ScoringService
{
    /**
     * Compute composite scores for all DeSOs for a given year.
     * Creates a new ScoreVersion and associates all scores with it.
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

        // Create a new score version
        $version = ScoreVersion::query()->create([
            'year' => $year,
            'status' => 'pending',
            'indicators_used' => $indicators->map(fn (Indicator $i) => [
                'slug' => $i->slug,
                'weight' => (float) $i->weight,
                'direction' => $i->direction,
            ])->toArray(),
            'computed_at' => now(),
            'computed_by' => 'system',
        ]);

        $indicatorData = $this->fetchIndicatorData($indicators, $year);

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
                'score_version_id' => $version->id,
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

        // Bulk insert (not upsert — each version gets its own rows)
        foreach (array_chunk($scores, 1000) as $chunk) {
            DB::table('composite_scores')->insert($chunk);
        }

        // Compute trends against published version
        $this->computeTrends($year, $version->id);

        // Update version stats
        $stats = DB::table('composite_scores')
            ->where('score_version_id', $version->id)
            ->selectRaw('COUNT(*) as cnt, AVG(score) as mean, STDDEV(score) as stddev')
            ->first();

        $version->update([
            'deso_count' => $stats->cnt,
            'mean_score' => round((float) $stats->mean, 2),
            'stddev_score' => round((float) ($stats->stddev ?? 0), 2),
        ]);

        return count($scores);
    }

    /**
     * Get the latest published ScoreVersion for a given year.
     */
    public function getPublishedVersion(int $year): ?ScoreVersion
    {
        return ScoreVersion::query()
            ->where('year', $year)
            ->where('status', 'published')
            ->latest('published_at')
            ->first();
    }

    /**
     * Get the latest score version (any status) for a year.
     */
    public function getLatestVersion(int $year): ?ScoreVersion
    {
        return ScoreVersion::query()
            ->where('year', $year)
            ->latest('computed_at')
            ->first();
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
            $values = DB::table('indicator_values')
                ->where('indicator_id', $indicator->id)
                ->where('year', $year)
                ->whereNotNull('normalized_value')
                ->pluck('normalized_value', 'deso_code')
                ->toArray();

            if (empty($values)) {
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

    private function computeTrends(int $year, int $versionId): void
    {
        // Find the previously published version for trend comparison
        $prevPublished = ScoreVersion::query()
            ->where('year', $year)
            ->where('status', 'published')
            ->latest('published_at')
            ->value('id');

        if (! $prevPublished) {
            // Fall back to any previous year scores
            $prevPublished = ScoreVersion::query()
                ->where('year', $year - 1)
                ->where('status', 'published')
                ->latest('published_at')
                ->value('id');
        }

        if ($prevPublished) {
            DB::update('
                UPDATE composite_scores cs
                SET trend_1y = cs.score - prev.score
                FROM composite_scores prev
                WHERE cs.score_version_id = ?
                  AND prev.score_version_id = ?
                  AND prev.deso_code = cs.deso_code
            ', [$versionId, $prevPublished]);
        }
    }
}
