<?php

namespace Tests\Feature;

use Tests\TestCase;

class QuestionnaireConfigTest extends TestCase
{
    public function test_config_has_required_keys(): void
    {
        $config = config('questionnaire');

        $this->assertNotNull($config);
        $this->assertArrayHasKey('priorities', $config);
        $this->assertArrayHasKey('max_priorities', $config);
        $this->assertArrayHasKey('walking_distances', $config);
        $this->assertArrayHasKey('default_walking_distance', $config);
        $this->assertArrayHasKey('ring_config', $config);
        $this->assertArrayHasKey('ring_rules', $config);
        $this->assertArrayHasKey('labels', $config);
    }

    public function test_priorities_have_required_fields(): void
    {
        $priorities = config('questionnaire.priorities');

        $this->assertNotEmpty($priorities);

        $requiredFields = ['label_sv', 'icon', 'weight_modifier', 'affected_categories', 'affected_proximity_factors'];

        foreach ($priorities as $key => $priority) {
            foreach ($requiredFields as $field) {
                $this->assertArrayHasKey(
                    $field,
                    $priority,
                    "Priority '{$key}' is missing required field '{$field}'"
                );
            }
        }
    }

    public function test_all_expected_priorities_exist(): void
    {
        $priorities = config('questionnaire.priorities');

        $expectedPriorities = [
            'schools',
            'safety',
            'green_areas',
            'shopping',
            'transit',
            'healthcare',
            'dining',
            'quiet',
        ];

        foreach ($expectedPriorities as $expected) {
            $this->assertArrayHasKey(
                $expected,
                $priorities,
                "Expected priority '{$expected}' is missing from config"
            );
        }
    }

    public function test_priority_weight_modifiers_are_valid(): void
    {
        $priorities = config('questionnaire.priorities');

        foreach ($priorities as $key => $priority) {
            $modifier = $priority['weight_modifier'];

            $this->assertIsFloat($modifier, "Weight modifier for '{$key}' should be a float");
            $this->assertGreaterThan(0, $modifier, "Weight modifier for '{$key}' should be positive");
            $this->assertLessThanOrEqual(2.0, $modifier, "Weight modifier for '{$key}' should not exceed 2.0");
        }
    }

    public function test_priority_affected_fields_are_arrays(): void
    {
        $priorities = config('questionnaire.priorities');

        foreach ($priorities as $key => $priority) {
            $this->assertIsArray(
                $priority['affected_categories'],
                "affected_categories for '{$key}' should be an array"
            );
            $this->assertIsArray(
                $priority['affected_proximity_factors'],
                "affected_proximity_factors for '{$key}' should be an array"
            );
        }
    }

    public function test_priority_labels_are_swedish(): void
    {
        $priorities = config('questionnaire.priorities');

        foreach ($priorities as $key => $priority) {
            $this->assertIsString($priority['label_sv'], "label_sv for '{$key}' should be a string");
            $this->assertNotEmpty($priority['label_sv'], "label_sv for '{$key}' should not be empty");
        }
    }

    public function test_priority_icons_are_fontawesome_names(): void
    {
        $priorities = config('questionnaire.priorities');

        foreach ($priorities as $key => $priority) {
            $this->assertIsString($priority['icon'], "icon for '{$key}' should be a string");
            $this->assertNotEmpty($priority['icon'], "icon for '{$key}' should not be empty");
            // FontAwesome icons use kebab-case
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9-]*$/',
                $priority['icon'],
                "icon for '{$key}' should be a valid FontAwesome icon name (kebab-case)"
            );
        }
    }

    public function test_max_priorities_is_valid(): void
    {
        $maxPriorities = config('questionnaire.max_priorities');

        $this->assertIsInt($maxPriorities);
        $this->assertGreaterThan(0, $maxPriorities);
        $this->assertLessThanOrEqual(count(config('questionnaire.priorities')), $maxPriorities);
    }

    public function test_walking_distances_are_valid(): void
    {
        $walkingDistances = config('questionnaire.walking_distances');

        $this->assertNotEmpty($walkingDistances);

        foreach ($walkingDistances as $minutes => $label) {
            $this->assertIsInt($minutes, "Walking distance key should be an integer (minutes)");
            $this->assertGreaterThan(0, $minutes, "Walking distance should be positive");
            $this->assertIsString($label, "Walking distance label should be a string");
            $this->assertNotEmpty($label, "Walking distance label should not be empty");
        }
    }

    public function test_default_walking_distance_is_in_options(): void
    {
        $default = config('questionnaire.default_walking_distance');
        $walkingDistances = config('questionnaire.walking_distances');

        $this->assertArrayHasKey(
            $default,
            $walkingDistances,
            "Default walking distance {$default} is not in walking_distances options"
        );
    }

    public function test_ring_config_has_required_rings(): void
    {
        $ringConfig = config('questionnaire.ring_config');

        $this->assertArrayHasKey('ring_1', $ringConfig);
        $this->assertArrayHasKey('ring_2_defaults', $ringConfig);
        $this->assertArrayHasKey('ring_3_defaults', $ringConfig);
    }

    public function test_ring_1_config_is_valid(): void
    {
        $ring1 = config('questionnaire.ring_config.ring_1');

        $this->assertArrayHasKey('minutes', $ring1);
        $this->assertArrayHasKey('mode', $ring1);
        $this->assertArrayHasKey('label_sv', $ring1);
        $this->assertArrayHasKey('color', $ring1);

        $this->assertEquals(5, $ring1['minutes'], "Ring 1 should always be 5 minutes");
        $this->assertEquals('pedestrian', $ring1['mode'], "Ring 1 should always be pedestrian mode");
        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $ring1['color'], "Ring 1 color should be valid hex");
    }

    public function test_ring_rules_cover_all_urbanity_tiers(): void
    {
        $ringRules = config('questionnaire.ring_rules');

        $this->assertArrayHasKey('urban', $ringRules);
        $this->assertArrayHasKey('semi_urban', $ringRules);
        $this->assertArrayHasKey('rural', $ringRules);
    }

    public function test_urban_ring_rules_are_valid(): void
    {
        $urbanRules = config('questionnaire.ring_rules.urban');

        $this->assertArrayHasKey('ring_count', $urbanRules);
        $this->assertEquals(2, $urbanRules['ring_count'], "Urban areas should have 2 rings");
        $this->assertArrayHasKey('ring_2', $urbanRules);
    }

    public function test_semi_urban_ring_rules_are_valid(): void
    {
        $semiUrbanRules = config('questionnaire.ring_rules.semi_urban');

        $this->assertArrayHasKey('ring_count', $semiUrbanRules);
        $this->assertEquals(2, $semiUrbanRules['ring_count'], "Semi-urban areas should have 2 rings");
        $this->assertArrayHasKey('ring_2', $semiUrbanRules);
    }

    public function test_rural_ring_rules_have_car_variants(): void
    {
        $ruralRules = config('questionnaire.ring_rules.rural');

        $this->assertArrayHasKey('with_car', $ruralRules);
        $this->assertArrayHasKey('without_car', $ruralRules);

        // With car should have 3 rings with driving option
        $withCar = $ruralRules['with_car'];
        $this->assertEquals(3, $withCar['ring_count'], "Rural with car should have 3 rings");
        $this->assertArrayHasKey('ring_3', $withCar);
        $this->assertEquals('auto', $withCar['ring_3']['mode'], "Ring 3 for rural with car should be auto mode");

        // Without car should have 3 walking rings
        $withoutCar = $ruralRules['without_car'];
        $this->assertEquals(3, $withoutCar['ring_count'], "Rural without car should have 3 rings");
        $this->assertArrayHasKey('ring_3', $withoutCar);
        $this->assertEquals('pedestrian', $withoutCar['ring_3']['mode'], "Ring 3 for rural without car should be pedestrian mode");
    }

    public function test_labels_have_required_keys(): void
    {
        $labels = config('questionnaire.labels');

        $requiredKeys = [
            'question_1_title',
            'question_1_subtitle',
            'question_2_title',
            'question_2_subtitle',
            'question_3_title',
            'question_3_subtitle',
            'yes',
            'no',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $labels, "Label '{$key}' is missing from config");
            $this->assertIsString($labels[$key], "Label '{$key}' should be a string");
            $this->assertNotEmpty($labels[$key], "Label '{$key}' should not be empty");
        }
    }

    public function test_labels_are_in_swedish(): void
    {
        $labels = config('questionnaire.labels');

        // Check for common Swedish characters or words
        $this->assertStringContainsString('för', $labels['question_1_title']);
        $this->assertStringContainsString('promenera', $labels['question_2_title']);
        $this->assertStringContainsString('bil', $labels['question_3_title']);
        $this->assertEquals('Ja', $labels['yes']);
        $this->assertEquals('Nej', $labels['no']);
    }

    public function test_each_priority_affects_at_least_one_thing(): void
    {
        $priorities = config('questionnaire.priorities');

        foreach ($priorities as $key => $priority) {
            $affectedCount = count($priority['affected_categories']) + count($priority['affected_proximity_factors']);

            $this->assertGreaterThan(
                0,
                $affectedCount,
                "Priority '{$key}' should affect at least one category or proximity factor"
            );
        }
    }
}
