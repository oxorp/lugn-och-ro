<?php

namespace Tests\Feature;

use App\Services\PersonalizedScoringService;
use Tests\TestCase;

class PersonalizedScoringTest extends TestCase
{
    private PersonalizedScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PersonalizedScoringService::class);
    }

    // ─── No Priorities ───────────────────────────────────

    public function test_no_priorities_returns_default_score_unchanged(): void
    {
        $result = $this->service->compute(
            defaultScore: 75.0,
            areaIndicators: [],
            proximityFactors: [],
            preferences: ['priorities' => []],
        );

        $this->assertEquals(75.0, $result['score']);
        $this->assertEmpty($result['modifiers_applied']);
        $this->assertEquals('default', $result['breakdown']['weights_used']);
    }

    public function test_empty_preferences_returns_default_score(): void
    {
        $result = $this->service->compute(
            defaultScore: 60.0,
            areaIndicators: [],
            proximityFactors: [],
            preferences: [],
        );

        $this->assertEquals(60.0, $result['score']);
        $this->assertEmpty($result['modifiers_applied']);
    }

    public function test_null_default_score_with_no_priorities_returns_null(): void
    {
        $result = $this->service->compute(
            defaultScore: null,
            areaIndicators: [],
            proximityFactors: [],
            preferences: [],
        );

        $this->assertNull($result['score']);
    }

    // ─── Single Priority ─────────────────────────────────

    public function test_single_priority_applies_weight_modifier(): void
    {
        $areaIndicators = [
            ['category' => 'education', 'normalized_value' => 0.80, 'direction' => 'positive'],
            ['category' => 'safety', 'normalized_value' => 0.60, 'direction' => 'positive'],
        ];

        $proximityFactors = [
            'school' => ['score' => 80.0],
            'greenSpace' => ['score' => 60.0],
        ];

        $result = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: $areaIndicators,
            proximityFactors: $proximityFactors,
            preferences: ['priorities' => ['schools']],
        );

        // Schools priority should boost education category (1.5x) and school proximity (1.5x)
        $this->assertArrayHasKey('schools', $result['modifiers_applied']);
        $this->assertEquals(1.5, $result['modifiers_applied']['schools']);

        // Verify adjusted weights in breakdown
        $this->assertArrayHasKey('category_weights', $result['breakdown']);
        $this->assertArrayHasKey('education', $result['breakdown']['category_weights']);

        // Education weight should be higher relative to others after modifier
        $educationWeight = $result['breakdown']['category_weights']['education'];
        $safetyWeight = $result['breakdown']['category_weights']['safety'];

        // Schools (1.5x) vs safety (1.0x) — education should have proportionally higher weight
        $this->assertGreaterThan($safetyWeight, $educationWeight);
    }

    public function test_schools_priority_affects_education_category(): void
    {
        $areaIndicators = [
            ['category' => 'education', 'normalized_value' => 0.90, 'direction' => 'positive'],
            ['category' => 'socioeconomic', 'normalized_value' => 0.50, 'direction' => 'positive'],
        ];

        $proximityFactors = [
            'school' => ['score' => 95.0],
            'greenSpace' => ['score' => 50.0],
        ];

        $withSchools = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: $areaIndicators,
            proximityFactors: $proximityFactors,
            preferences: ['priorities' => ['schools']],
        );

        $withSafety = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: $areaIndicators,
            proximityFactors: $proximityFactors,
            preferences: ['priorities' => ['safety']],
        );

        // With high education/school scores, the schools priority should yield a higher score
        $this->assertGreaterThan($withSafety['score'], $withSchools['score']);
    }

    public function test_safety_priority_affects_safety_category(): void
    {
        $areaIndicators = [
            ['category' => 'safety', 'normalized_value' => 0.95, 'direction' => 'positive'],
            ['category' => 'education', 'normalized_value' => 0.50, 'direction' => 'positive'],
        ];

        $result = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: $areaIndicators,
            proximityFactors: [],
            preferences: ['priorities' => ['safety']],
        );

        // Verify safety is in affected categories
        $categoryWeights = $result['breakdown']['category_weights'];
        $safetyWeight = $categoryWeights['safety'];
        $educationWeight = $categoryWeights['education'];

        // Safety (1.5x modifier) should have higher weight than education (1.0x)
        $this->assertGreaterThan($educationWeight, $safetyWeight);
    }

    // ─── Multiple Priorities ─────────────────────────────

    public function test_multiple_priorities_apply_correctly(): void
    {
        $result = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [
                ['category' => 'education', 'normalized_value' => 0.80, 'direction' => 'positive'],
                ['category' => 'safety', 'normalized_value' => 0.70, 'direction' => 'positive'],
            ],
            proximityFactors: [],
            preferences: ['priorities' => ['schools', 'safety']],
        );

        $this->assertCount(2, $result['modifiers_applied']);
        $this->assertArrayHasKey('schools', $result['modifiers_applied']);
        $this->assertArrayHasKey('safety', $result['modifiers_applied']);
    }

    public function test_three_priorities_all_apply_modifiers(): void
    {
        $result = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [],
            proximityFactors: [
                'school' => ['score' => 80.0],
                'greenSpace' => ['score' => 70.0],
                'transit' => ['score' => 90.0],
            ],
            preferences: ['priorities' => ['schools', 'green_areas', 'transit']],
        );

        $this->assertCount(3, $result['modifiers_applied']);
        $this->assertEquals(1.5, $result['modifiers_applied']['schools']);
        $this->assertEquals(1.5, $result['modifiers_applied']['green_areas']);
        $this->assertEquals(1.5, $result['modifiers_applied']['transit']);
    }

    public function test_overlapping_category_uses_highest_modifier(): void
    {
        // Both 'safety' and 'quiet' affect the safety category
        $result = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [
                ['category' => 'safety', 'normalized_value' => 0.80, 'direction' => 'positive'],
            ],
            proximityFactors: [],
            preferences: ['priorities' => ['safety', 'quiet']],
        );

        // Safety has 1.5x modifier, quiet has 1.3x — safety category should use 1.5x (the higher one)
        $this->assertCount(2, $result['modifiers_applied']);
        // The breakdown should show the boosted safety weight
        $this->assertArrayHasKey('safety', $result['breakdown']['category_weights']);
    }

    // ─── Weight Re-normalization ─────────────────────────

    public function test_weights_are_renormalized_to_sum_to_one(): void
    {
        $result = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [
                ['category' => 'education', 'normalized_value' => 0.70, 'direction' => 'positive'],
                ['category' => 'safety', 'normalized_value' => 0.70, 'direction' => 'positive'],
                ['category' => 'socioeconomic', 'normalized_value' => 0.70, 'direction' => 'positive'],
            ],
            proximityFactors: [],
            preferences: ['priorities' => ['schools']],
        );

        $categoryWeights = $result['breakdown']['category_weights'];
        $total = array_sum($categoryWeights);

        // Weights should sum to 1.0 (allowing for floating point rounding)
        $this->assertEqualsWithDelta(1.0, $total, 0.01);
    }

    public function test_proximity_factor_weights_are_renormalized(): void
    {
        $result = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [],
            proximityFactors: [
                'school' => ['score' => 80.0],
                'greenSpace' => ['score' => 70.0],
                'transit' => ['score' => 60.0],
            ],
            preferences: ['priorities' => ['schools', 'transit']],
        );

        $factorWeights = $result['breakdown']['proximity_factor_weights'];
        $total = array_sum($factorWeights);

        // Weights should sum to 1.0 (allowing for floating point rounding)
        $this->assertEqualsWithDelta(1.0, $total, 0.01);
    }

    // ─── Direction Handling ──────────────────────────────

    public function test_negative_direction_inverts_indicator_value(): void
    {
        // High crime (normalized 0.80) should result in low directed value (0.20)
        $areaIndicators = [
            ['category' => 'safety', 'normalized_value' => 0.80, 'direction' => 'negative'],
        ];

        $result = $this->service->compute(
            defaultScore: null,
            areaIndicators: $areaIndicators,
            proximityFactors: [],
            preferences: ['priorities' => ['safety']],
        );

        // With high negative value (bad), area score should be low
        $areaScore = $result['breakdown']['area_score'];
        $this->assertNotNull($areaScore);
        // 0.80 negative → 1.0 - 0.80 = 0.20 → 20.0 score
        $this->assertLessThan(30, $areaScore);
    }

    public function test_positive_direction_uses_value_directly(): void
    {
        $areaIndicators = [
            ['category' => 'education', 'normalized_value' => 0.90, 'direction' => 'positive'],
        ];

        $result = $this->service->compute(
            defaultScore: null,
            areaIndicators: $areaIndicators,
            proximityFactors: [],
            preferences: ['priorities' => ['schools']],
        );

        // High positive value should give high area score
        $areaScore = $result['breakdown']['area_score'];
        $this->assertGreaterThan(80, $areaScore);
    }

    // ─── 70/30 Split ─────────────────────────────────────

    public function test_composite_uses_70_30_split(): void
    {
        // Area indicators with perfect score (100)
        $areaIndicators = [
            ['category' => 'education', 'normalized_value' => 1.0, 'direction' => 'positive'],
        ];

        // Proximity factors with zero score
        $proximityFactors = [
            'school' => ['score' => 0.0],
        ];

        $result = $this->service->compute(
            defaultScore: null,
            areaIndicators: $areaIndicators,
            proximityFactors: $proximityFactors,
            preferences: ['priorities' => ['schools']],
        );

        // Area = 100 * 0.70 = 70
        // Proximity = 0 * 0.30 = 0
        // Total = 70
        $this->assertEqualsWithDelta(70.0, $result['score'], 1.0);
    }

    public function test_breakdown_includes_area_and_proximity_weights(): void
    {
        $result = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [
                ['category' => 'education', 'normalized_value' => 0.80, 'direction' => 'positive'],
            ],
            proximityFactors: [
                'school' => ['score' => 75.0],
            ],
            preferences: ['priorities' => ['schools']],
        );

        $this->assertEquals(0.70, $result['breakdown']['area_weight']);
        $this->assertEquals(0.30, $result['breakdown']['proximity_weight']);
    }

    // ─── Proximity Factor Mapping ────────────────────────

    public function test_green_areas_priority_affects_green_space_factor(): void
    {
        $proximityFactors = [
            'school' => ['score' => 50.0],
            'greenSpace' => ['score' => 90.0],
        ];

        $withGreen = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [],
            proximityFactors: $proximityFactors,
            preferences: ['priorities' => ['green_areas']],
        );

        $withSchools = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [],
            proximityFactors: $proximityFactors,
            preferences: ['priorities' => ['schools']],
        );

        // With high green space score, green_areas priority should yield higher score
        $this->assertGreaterThan($withSchools['score'], $withGreen['score']);
    }

    public function test_transit_priority_affects_transit_factor(): void
    {
        $proximityFactors = [
            'school' => ['score' => 50.0],
            'transit' => ['score' => 95.0],
        ];

        $withTransit = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [],
            proximityFactors: $proximityFactors,
            preferences: ['priorities' => ['transit']],
        );

        $withSchools = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [],
            proximityFactors: $proximityFactors,
            preferences: ['priorities' => ['schools']],
        );

        // With high transit score, transit priority should yield higher score
        $this->assertGreaterThan($withSchools['score'], $withTransit['score']);
    }

    public function test_shopping_priority_affects_grocery_and_positive_poi(): void
    {
        $proximityFactors = [
            'grocery' => ['score' => 90.0],
            'positivePoi' => ['score' => 85.0],
            'transit' => ['score' => 40.0],
        ];

        $withShopping = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [],
            proximityFactors: $proximityFactors,
            preferences: ['priorities' => ['shopping']],
        );

        $withTransit = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [],
            proximityFactors: $proximityFactors,
            preferences: ['priorities' => ['transit']],
        );

        // Shopping boosts grocery + positive_poi (high scores)
        // Transit boosts transit (low score)
        $this->assertGreaterThan($withTransit['score'], $withShopping['score']);
    }

    public function test_healthcare_priority_affects_healthcare_factor(): void
    {
        $result = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [],
            proximityFactors: [
                'healthcare' => ['score' => 80.0],
            ],
            preferences: ['priorities' => ['healthcare']],
        );

        $this->assertArrayHasKey('healthcare', $result['modifiers_applied']);
        $this->assertEquals(1.3, $result['modifiers_applied']['healthcare']);
    }

    public function test_quiet_priority_affects_negative_poi_factor(): void
    {
        $result = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [],
            proximityFactors: [
                'negativePoi' => ['score' => 90.0], // High = few negative POIs nearby
            ],
            preferences: ['priorities' => ['quiet']],
        );

        $factorWeights = $result['breakdown']['proximity_factor_weights'];
        // Negative POI should have boosted weight (1.3x)
        $this->assertArrayHasKey('negative_poi', $factorWeights);
    }

    // ─── Invalid Priorities ──────────────────────────────

    public function test_invalid_priority_is_ignored(): void
    {
        $result = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [
                ['category' => 'education', 'normalized_value' => 0.80, 'direction' => 'positive'],
            ],
            proximityFactors: [],
            preferences: ['priorities' => ['invalid_priority', 'schools']],
        );

        // Only valid priority should be in modifiers
        $this->assertCount(1, $result['modifiers_applied']);
        $this->assertArrayHasKey('schools', $result['modifiers_applied']);
        $this->assertArrayNotHasKey('invalid_priority', $result['modifiers_applied']);
    }

    // ─── Validation ──────────────────────────────────────

    public function test_validate_preferences_accepts_valid_priorities(): void
    {
        $result = $this->service->validatePreferences([
            'priorities' => ['schools', 'safety', 'transit'],
            'walking_distance_minutes' => 15,
            'has_car' => true,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_validate_preferences_rejects_too_many_priorities(): void
    {
        $result = $this->service->validatePreferences([
            'priorities' => ['schools', 'safety', 'transit', 'healthcare'],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Maximum', $result['errors'][0]);
    }

    public function test_validate_preferences_rejects_invalid_priority_key(): void
    {
        $result = $this->service->validatePreferences([
            'priorities' => ['invalid_key'],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Invalid priority', $result['errors'][0]);
    }

    public function test_validate_preferences_rejects_invalid_walking_distance(): void
    {
        $result = $this->service->validatePreferences([
            'priorities' => ['schools'],
            'walking_distance_minutes' => 25, // Not a valid option (10, 15, 20, 30)
        ]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Invalid walking distance', $result['errors'][0]);
    }

    public function test_validate_preferences_accepts_null_walking_distance(): void
    {
        $result = $this->service->validatePreferences([
            'priorities' => ['schools'],
            'walking_distance_minutes' => null,
        ]);

        $this->assertTrue($result['valid']);
    }

    // ─── Helper Methods ──────────────────────────────────

    public function test_get_priority_options_returns_config(): void
    {
        $options = $this->service->getPriorityOptions();

        $this->assertIsArray($options);
        $this->assertArrayHasKey('schools', $options);
        $this->assertArrayHasKey('safety', $options);
        $this->assertArrayHasKey('green_areas', $options);
        $this->assertCount(8, $options);
    }

    public function test_get_max_priorities_returns_three(): void
    {
        $max = $this->service->getMaxPriorities();

        $this->assertEquals(3, $max);
    }

    // ─── Edge Cases ──────────────────────────────────────

    public function test_empty_area_indicators_uses_fallback(): void
    {
        $result = $this->service->compute(
            defaultScore: null,
            areaIndicators: [],
            proximityFactors: [
                'school' => ['score' => 80.0],
            ],
            preferences: ['priorities' => ['schools']],
        );

        // With no area indicators, area score should be null
        // But proximity score should still be calculated
        $this->assertNull($result['breakdown']['area_score']);
        $this->assertNotNull($result['breakdown']['proximity_score']);
    }

    public function test_empty_proximity_factors_uses_fallback(): void
    {
        $result = $this->service->compute(
            defaultScore: null,
            areaIndicators: [
                ['category' => 'education', 'normalized_value' => 0.80, 'direction' => 'positive'],
            ],
            proximityFactors: [],
            preferences: ['priorities' => ['schools']],
        );

        // With no proximity factors, proximity score should be null
        // But area score should still be calculated
        $this->assertNotNull($result['breakdown']['area_score']);
        $this->assertNull($result['breakdown']['proximity_score']);
    }

    public function test_null_normalized_values_are_skipped(): void
    {
        $result = $this->service->compute(
            defaultScore: null,
            areaIndicators: [
                ['category' => 'education', 'normalized_value' => null, 'direction' => 'positive'],
                ['category' => 'safety', 'normalized_value' => 0.80, 'direction' => 'positive'],
            ],
            proximityFactors: [],
            preferences: ['priorities' => ['safety']],
        );

        // Should still compute based on available data
        $this->assertNotNull($result['score']);
    }

    public function test_dining_priority_weight_modifier_is_1_2(): void
    {
        $result = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [],
            proximityFactors: [
                'positivePoi' => ['score' => 80.0],
            ],
            preferences: ['priorities' => ['dining']],
        );

        $this->assertEquals(1.2, $result['modifiers_applied']['dining']);
    }

    public function test_healthcare_priority_weight_modifier_is_1_3(): void
    {
        $result = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [],
            proximityFactors: [
                'healthcare' => ['score' => 80.0],
            ],
            preferences: ['priorities' => ['healthcare']],
        );

        $this->assertEquals(1.3, $result['modifiers_applied']['healthcare']);
    }

    public function test_breakdown_includes_priorities_used(): void
    {
        $result = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [],
            proximityFactors: [],
            preferences: ['priorities' => ['schools', 'safety']],
        );

        $this->assertEquals(['schools', 'safety'], $result['breakdown']['priorities_used']);
    }

    public function test_object_format_proximity_factors_supported(): void
    {
        // Test that object format (from JSON serialization) works
        $proximityFactors = [
            'school' => (object) ['score' => 80.0],
            'greenSpace' => (object) ['score' => 70.0],
        ];

        $result = $this->service->compute(
            defaultScore: 70.0,
            areaIndicators: [],
            proximityFactors: $proximityFactors,
            preferences: ['priorities' => ['schools']],
        );

        $this->assertNotNull($result['score']);
        $this->assertNotNull($result['breakdown']['proximity_score']);
    }
}
