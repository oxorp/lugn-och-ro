<?php

namespace Tests\Feature;

use Tests\TestCase;

class ScoreColorsConfigTest extends TestCase
{
    public function test_config_has_required_keys(): void
    {
        $config = config('score_colors');

        $this->assertNotNull($config);
        $this->assertArrayHasKey('gradient_stops', $config);
        $this->assertArrayHasKey('labels', $config);
        $this->assertArrayHasKey('no_data', $config);
        $this->assertArrayHasKey('school_markers', $config);
        $this->assertArrayHasKey('indicator_bar', $config);
    }

    public function test_gradient_stops_are_valid_hex_colors(): void
    {
        $stops = config('score_colors.gradient_stops');

        $this->assertNotEmpty($stops);
        $this->assertArrayHasKey(0, $stops);
        $this->assertArrayHasKey(100, $stops);

        foreach ($stops as $threshold => $color) {
            $this->assertIsInt($threshold);
            $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $color, "Invalid hex color at threshold {$threshold}: {$color}");
            $this->assertGreaterThanOrEqual(0, $threshold);
            $this->assertLessThanOrEqual(100, $threshold);
        }
    }

    public function test_labels_cover_full_score_range(): void
    {
        $labels = config('score_colors.labels');

        $this->assertCount(5, $labels);

        // Verify every score from 0-100 has a label
        for ($score = 0; $score <= 100; $score++) {
            $found = false;
            foreach ($labels as $label) {
                if ($score >= $label['min'] && $score <= $label['max']) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Score {$score} has no matching label");
        }
    }

    public function test_labels_have_required_fields(): void
    {
        $labels = config('score_colors.labels');

        foreach ($labels as $label) {
            $this->assertArrayHasKey('min', $label);
            $this->assertArrayHasKey('max', $label);
            $this->assertArrayHasKey('label_sv', $label);
            $this->assertArrayHasKey('label_en', $label);
            $this->assertArrayHasKey('color', $label);
            $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $label['color']);
        }
    }

    public function test_school_markers_have_all_tiers(): void
    {
        $markers = config('score_colors.school_markers');

        $this->assertArrayHasKey('high', $markers);
        $this->assertArrayHasKey('medium', $markers);
        $this->assertArrayHasKey('low', $markers);
        $this->assertArrayHasKey('no_data', $markers);
    }

    public function test_indicator_bar_has_all_states(): void
    {
        $bar = config('score_colors.indicator_bar');

        $this->assertArrayHasKey('good', $bar);
        $this->assertArrayHasKey('bad', $bar);
        $this->assertArrayHasKey('neutral', $bar);
    }

    public function test_score_colors_shared_via_inertia(): void
    {
        $response = $this->get('/');

        $response->assertInertia(function ($page) {
            $page->has('scoreColors');
            $page->has('scoreColors.gradient_stops');
            $page->has('scoreColors.labels');
        });
    }
}
