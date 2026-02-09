<?php

namespace App\Services;

use App\Models\Indicator;
use App\Models\ScorePenalty;
use App\Models\ScoreVersion;
use App\Models\Tenant;
use App\Models\TenantIndicatorWeight;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScoringService
{
    /**
     * Compute composite scores for all DeSOs for a given year.
     * Creates a new ScoreVersion and associates all scores with it.
     *
     * If a tenant is provided, uses tenant-specific weights from tenant_indicator_weights.
     * Otherwise, uses the default weights from the indicators table (public scores).
     */
    public function computeScores(int $year, ?Tenant $tenant = null): int
    {
        $weightMap = $this->resolveWeights($tenant);

        if ($weightMap->isEmpty()) {
            return 0;
        }

        $indicators = Indicator::query()->whereIn('id', $weightMap->keys())->get();

        // Create a new score version
        $version = ScoreVersion::query()->create([
            'year' => $year,
            'tenant_id' => $tenant?->id,
            'status' => 'pending',
            'indicators_used' => $indicators->map(fn (Indicator $i) => [
                'slug' => $i->slug,
                'weight' => (float) $weightMap[$i->id]['weight'],
                'direction' => $weightMap[$i->id]['direction'],
            ])->toArray(),
            'computed_at' => now(),
            'computed_by' => $tenant ? "tenant:{$tenant->uuid}" : 'system',
        ]);

        $indicatorData = $this->fetchIndicatorData($indicators, $year);

        $desoCodes = array_unique(array_merge(
            ...array_values(array_map('array_keys', $indicatorData))
        ));

        // Load active penalties and vulnerability mappings
        $penalties = ScorePenalty::query()
            ->where('is_active', true)
            ->where('applies_to', 'composite_score')
            ->get();

        $desoVulnerabilities = $this->loadDesoVulnerabilityMappings();

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

                $w = $weightMap[$indicator->id];

                $directedValue = match ($w['direction']) {
                    'positive' => (float) $normalizedValue,
                    'negative' => 1.0 - (float) $normalizedValue,
                    default => null,
                };

                if ($directedValue === null) {
                    continue;
                }

                $weightedSum += $w['weight'] * $directedValue;
                $availableWeight += (float) $w['weight'];
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

            $rawScore = round(($weightedSum / $availableWeight) * 100, 2);

            // Apply penalties (worst per category only)
            $appliedPenalties = $this->computePenalties($desoCode, $rawScore, $penalties, $desoVulnerabilities);
            $totalPenalty = array_sum(array_column($appliedPenalties, 'amount'));
            $finalScore = max(0, min(100, round($rawScore + $totalPenalty, 2)));

            $scores[] = [
                'deso_code' => $desoCode,
                'year' => $year,
                'score_version_id' => $version->id,
                'score' => $finalScore,
                'raw_score_before_penalties' => ! empty($appliedPenalties) ? $rawScore : null,
                'trend_1y' => null,
                'trend_3y' => null,
                'factor_scores' => json_encode($factorScores),
                'top_positive' => json_encode($topPositive),
                'top_negative' => json_encode($topNegative),
                'penalties_applied' => ! empty($appliedPenalties) ? json_encode($appliedPenalties) : null,
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
     * Compute penalties for a DeSO, applying only the worst penalty per category.
     *
     * @param  Collection<int, ScorePenalty>  $penalties
     * @param  Collection<string, Collection>  $desoVulnerabilities
     * @return array<int, array{slug: string, name: string, amount: float}>
     */
    private function computePenalties(
        string $desoCode,
        float $rawScore,
        Collection $penalties,
        Collection $desoVulnerabilities,
    ): array {
        $applicable = [];

        foreach ($penalties as $penalty) {
            if ($this->penaltyApplies($desoCode, $penalty, $desoVulnerabilities)) {
                $amount = match ($penalty->penalty_type) {
                    'absolute' => (float) $penalty->penalty_value,
                    'percentage' => $rawScore * ((float) $penalty->penalty_value / 100),
                    default => 0.0,
                };

                $applicable[] = [
                    'slug' => $penalty->slug,
                    'name' => $penalty->name,
                    'category' => $penalty->category,
                    'amount' => round($amount, 2),
                ];
            }
        }

        if (empty($applicable)) {
            return [];
        }

        // Apply only the worst penalty per category (most negative)
        $byCategory = collect($applicable)->groupBy('category');
        $effective = [];

        foreach ($byCategory as $group) {
            $worst = $group->sortBy('amount')->first();
            $effective[] = [
                'slug' => $worst['slug'],
                'name' => $worst['name'],
                'amount' => $worst['amount'],
            ];
        }

        return $effective;
    }

    /**
     * Check if a penalty applies to a given DeSO.
     *
     * @param  Collection<string, Collection>  $mappings
     */
    private function penaltyApplies(string $desoCode, ScorePenalty $penalty, Collection $mappings): bool
    {
        if ($penalty->category !== 'vulnerability') {
            return false;
        }

        $desoMappings = $mappings->get($desoCode, collect());

        return match ($penalty->slug) {
            'vuln_sarskilt_utsatt' => $desoMappings->contains(fn ($m) => $m->tier === 'sarskilt_utsatt'),
            'vuln_utsatt' => $desoMappings->contains(fn ($m) => $m->tier === 'utsatt'),
            default => false,
        };
    }

    /**
     * Load DeSO vulnerability mappings with >= 10% overlap.
     *
     * @return Collection<string, Collection>
     */
    private function loadDesoVulnerabilityMappings(): Collection
    {
        return DB::table('deso_vulnerability_mapping')
            ->where('overlap_fraction', '>=', 0.10)
            ->get()
            ->groupBy('deso_code');
    }

    /**
     * Resolve indicator weights — from tenant or from indicator defaults.
     *
     * @return \Illuminate\Support\Collection<int, array{weight: float, direction: string}>
     */
    private function resolveWeights(?Tenant $tenant): \Illuminate\Support\Collection
    {
        if ($tenant) {
            return TenantIndicatorWeight::query()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->where('weight', '>', 0)
                ->get()
                ->keyBy('indicator_id')
                ->map(fn (TenantIndicatorWeight $w) => [
                    'weight' => (float) $w->weight,
                    'direction' => $w->direction,
                ]);
        }

        return Indicator::query()
            ->where('is_active', true)
            ->where('weight', '>', 0)
            ->get()
            ->keyBy('id')
            ->map(fn (Indicator $i) => [
                'weight' => (float) $i->weight,
                'direction' => $i->direction,
            ]);
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
