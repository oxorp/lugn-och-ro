<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Personalized scoring service for applying priority-based weight modifiers.
 *
 * This service computes personalized scores by adjusting the weight of different
 * scoring factors based on user-selected priorities. It takes the base area
 * indicators and proximity factors, then applies weight modifiers from the
 * questionnaire configuration.
 *
 * Scoring Architecture:
 * - Area Score (70% base weight) — DeSO-level indicators grouped by category
 * - Proximity Score (30% base weight) — Pin-level proximity factors
 *
 * When a user selects priorities:
 * - The affected_categories get their weights multiplied by weight_modifier
 * - The affected_proximity_factors get their weights multiplied by weight_modifier
 * - All weights are then re-normalized to sum to 1.0
 *
 * This creates differentiated scores based on what matters most to each user
 * without modifying the underlying indicator data.
 */
class PersonalizedScoringService
{
    /** Base weight allocation for area indicators (70%) */
    private const AREA_WEIGHT = 0.70;

    /** Base weight allocation for proximity factors (30%) */
    private const PROXIMITY_WEIGHT = 0.30;

    /** Default weights per indicator category (must sum to 1.0 within area portion) */
    private const CATEGORY_WEIGHTS = [
        'socioeconomic' => 0.20,
        'education' => 0.20,
        'safety' => 0.25,
        'proximity' => 0.15,
        'financial' => 0.20,
    ];

    /** Default weights per proximity factor (must sum to 1.0 within proximity portion) */
    private const PROXIMITY_FACTOR_WEIGHTS = [
        'school' => 0.20,
        'green_space' => 0.15,
        'transit' => 0.20,
        'grocery' => 0.15,
        'negative_poi' => 0.15,
        'positive_poi' => 0.10,
        'healthcare' => 0.05,
    ];

    /**
     * Compute a personalized score based on user preferences.
     *
     * @param  float|null  $defaultScore  The default composite score (0-100)
     * @param  array<int, array<string, mixed>>  $areaIndicators  Area indicators with 'category' and 'normalized_value'
     * @param  array<string, mixed>  $proximityFactors  Proximity factors with scores per factor
     * @param  array{priorities?: string[], walking_distance_minutes?: int, has_car?: bool|null}  $preferences  User preferences
     * @return array{score: float|null, modifiers_applied: array<string, float>, breakdown: array<string, mixed>}
     */
    public function compute(
        ?float $defaultScore,
        array $areaIndicators,
        array $proximityFactors,
        array $preferences,
    ): array {
        $priorities = $preferences['priorities'] ?? [];

        // If no priorities selected, return default score unchanged
        if (empty($priorities)) {
            return [
                'score' => $defaultScore,
                'modifiers_applied' => [],
                'breakdown' => [
                    'area_score' => null,
                    'proximity_score' => null,
                    'weights_used' => 'default',
                ],
            ];
        }

        // Build weight modifiers from selected priorities
        $modifiers = $this->buildModifiers($priorities);

        // Calculate adjusted category weights
        $adjustedCategoryWeights = $this->applyModifiers(
            self::CATEGORY_WEIGHTS,
            $modifiers['categories'],
        );

        // Calculate adjusted proximity factor weights
        $adjustedProximityWeights = $this->applyModifiers(
            self::PROXIMITY_FACTOR_WEIGHTS,
            $modifiers['proximity_factors'],
        );

        // Compute area score with adjusted weights
        $areaScore = $this->computeAreaScore($areaIndicators, $adjustedCategoryWeights);

        // Compute proximity score with adjusted weights
        $proximityScore = $this->computeProximityScore($proximityFactors, $adjustedProximityWeights);

        // Combine into final personalized score
        $personalizedScore = null;
        if ($areaScore !== null || $proximityScore !== null) {
            $areaContrib = ($areaScore ?? 50.0) * self::AREA_WEIGHT;
            $proxContrib = ($proximityScore ?? 50.0) * self::PROXIMITY_WEIGHT;
            $personalizedScore = round($areaContrib + $proxContrib, 2);
        }

        return [
            'score' => $personalizedScore,
            'modifiers_applied' => $modifiers['raw'],
            'breakdown' => [
                'area_score' => $areaScore !== null ? round($areaScore, 2) : null,
                'proximity_score' => $proximityScore !== null ? round($proximityScore, 2) : null,
                'area_weight' => self::AREA_WEIGHT,
                'proximity_weight' => self::PROXIMITY_WEIGHT,
                'category_weights' => $adjustedCategoryWeights,
                'proximity_factor_weights' => $adjustedProximityWeights,
                'priorities_used' => $priorities,
            ],
        ];
    }

    /**
     * Build weight modifiers from selected priorities.
     *
     * @param  string[]  $priorities
     * @return array{categories: array<string, float>, proximity_factors: array<string, float>, raw: array<string, float>}
     */
    private function buildModifiers(array $priorities): array
    {
        $priorityConfig = config('questionnaire.priorities', []);

        $categoryModifiers = [];
        $proximityModifiers = [];
        $rawModifiers = [];

        foreach ($priorities as $priorityKey) {
            $priority = $priorityConfig[$priorityKey] ?? null;
            if (! $priority) {
                continue;
            }

            $modifier = (float) ($priority['weight_modifier'] ?? 1.0);
            $rawModifiers[$priorityKey] = $modifier;

            // Apply to affected categories
            foreach ($priority['affected_categories'] ?? [] as $category) {
                $categoryModifiers[$category] = max(
                    $categoryModifiers[$category] ?? 1.0,
                    $modifier,
                );
            }

            // Apply to affected proximity factors
            foreach ($priority['affected_proximity_factors'] ?? [] as $factor) {
                $proximityModifiers[$factor] = max(
                    $proximityModifiers[$factor] ?? 1.0,
                    $modifier,
                );
            }
        }

        return [
            'categories' => $categoryModifiers,
            'proximity_factors' => $proximityModifiers,
            'raw' => $rawModifiers,
        ];
    }

    /**
     * Apply modifiers to base weights and re-normalize.
     *
     * @param  array<string, float>  $baseWeights
     * @param  array<string, float>  $modifiers
     * @return array<string, float>
     */
    private function applyModifiers(array $baseWeights, array $modifiers): array
    {
        $adjusted = [];

        foreach ($baseWeights as $key => $weight) {
            $modifier = $modifiers[$key] ?? 1.0;
            $adjusted[$key] = $weight * $modifier;
        }

        // Re-normalize to sum to 1.0
        $total = array_sum($adjusted);
        if ($total > 0) {
            foreach ($adjusted as $key => $weight) {
                $adjusted[$key] = round($weight / $total, 4);
            }
        }

        return $adjusted;
    }

    /**
     * Compute weighted area score from indicators.
     *
     * @param  array<int, array<string, mixed>>  $indicators
     * @param  array<string, float>  $categoryWeights
     */
    private function computeAreaScore(array $indicators, array $categoryWeights): ?float
    {
        if (empty($indicators)) {
            return null;
        }

        // Group indicators by category
        $byCategory = collect($indicators)->groupBy('category');

        $weightedSum = 0.0;
        $totalWeight = 0.0;

        foreach ($categoryWeights as $category => $weight) {
            $categoryIndicators = $byCategory->get($category, collect());

            if ($categoryIndicators->isEmpty()) {
                continue;
            }

            // Compute average directed percentile for this category
            $categoryScore = $this->computeCategoryScore($categoryIndicators);

            if ($categoryScore !== null) {
                $weightedSum += $categoryScore * $weight;
                $totalWeight += $weight;
            }
        }

        if ($totalWeight === 0.0) {
            return null;
        }

        return $weightedSum / $totalWeight;
    }

    /**
     * Compute average directed score for a category's indicators.
     *
     * @param  Collection<int, array<string, mixed>>  $indicators
     */
    private function computeCategoryScore(Collection $indicators): ?float
    {
        $scores = [];

        foreach ($indicators as $indicator) {
            $normalizedValue = $indicator['normalized_value'] ?? null;
            $direction = $indicator['direction'] ?? 'positive';

            if ($normalizedValue === null) {
                continue;
            }

            // Convert to directed value (higher = better)
            $directedValue = match ($direction) {
                'negative' => 1.0 - (float) $normalizedValue,
                default => (float) $normalizedValue,
            };

            // Convert to 0-100 scale
            $scores[] = $directedValue * 100;
        }

        if (empty($scores)) {
            return null;
        }

        return array_sum($scores) / count($scores);
    }

    /**
     * Compute weighted proximity score from factors.
     *
     * @param  array<string, mixed>  $proximityFactors
     * @param  array<string, float>  $factorWeights
     */
    private function computeProximityScore(array $proximityFactors, array $factorWeights): ?float
    {
        if (empty($proximityFactors)) {
            return null;
        }

        // Map factor keys to proximity result keys
        $factorKeyMap = [
            'school' => 'school',
            'green_space' => 'greenSpace',
            'transit' => 'transit',
            'grocery' => 'grocery',
            'negative_poi' => 'negativePoi',
            'positive_poi' => 'positivePoi',
            'healthcare' => 'healthcare',
        ];

        $weightedSum = 0.0;
        $totalWeight = 0.0;

        foreach ($factorWeights as $factor => $weight) {
            $resultKey = $factorKeyMap[$factor] ?? $factor;
            $factorData = $proximityFactors[$resultKey] ?? null;

            // Support both array format (from serialization) and object format
            $score = null;
            if (is_array($factorData) && isset($factorData['score'])) {
                $score = (float) $factorData['score'];
            } elseif (is_object($factorData) && isset($factorData->score)) {
                $score = (float) $factorData->score;
            }

            if ($score !== null) {
                $weightedSum += $score * $weight;
                $totalWeight += $weight;
            }
        }

        if ($totalWeight === 0.0) {
            return null;
        }

        return $weightedSum / $totalWeight;
    }

    /**
     * Get the available priority options from configuration.
     *
     * @return array<string, array{label_sv: string, icon: string, weight_modifier: float}>
     */
    public function getPriorityOptions(): array
    {
        return config('questionnaire.priorities', []);
    }

    /**
     * Get the maximum number of priorities a user can select.
     */
    public function getMaxPriorities(): int
    {
        return (int) config('questionnaire.max_priorities', 3);
    }

    /**
     * Validate user preferences.
     *
     * @param  array{priorities?: string[], walking_distance_minutes?: int, has_car?: bool|null}  $preferences
     * @return array{valid: bool, errors: string[]}
     */
    public function validatePreferences(array $preferences): array
    {
        $errors = [];
        $validPriorityKeys = array_keys(config('questionnaire.priorities', []));
        $validWalkingDistances = array_keys(config('questionnaire.walking_distances', []));

        // Validate priorities
        $priorities = $preferences['priorities'] ?? [];
        if (count($priorities) > $this->getMaxPriorities()) {
            $errors[] = "Maximum {$this->getMaxPriorities()} priorities allowed.";
        }

        foreach ($priorities as $priority) {
            if (! in_array($priority, $validPriorityKeys, true)) {
                $errors[] = "Invalid priority: {$priority}";
            }
        }

        // Validate walking distance
        $walkingDistance = $preferences['walking_distance_minutes'] ?? null;
        if ($walkingDistance !== null && ! in_array($walkingDistance, $validWalkingDistances, true)) {
            $errors[] = "Invalid walking distance: {$walkingDistance}";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
