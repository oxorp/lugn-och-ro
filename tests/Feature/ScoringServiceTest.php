<?php

namespace Tests\Feature;

use App\Models\CompositeScore;
use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScoringService;
    }

    public function test_computes_score_for_single_indicator(): void
    {
        $indicator = Indicator::query()->create([
            'slug' => 'test_income',
            'name' => 'Test Income',
            'source' => 'test',
            'direction' => 'positive',
            'weight' => 0.5,
            'is_active' => true,
        ]);

        IndicatorValue::query()->create([
            'deso_code' => '0180C0001',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 300000,
            'normalized_value' => 0.75,
        ]);

        $count = $this->service->computeScores(2024);

        $this->assertEquals(1, $count);

        $score = CompositeScore::query()
            ->where('deso_code', '0180C0001')
            ->where('year', 2024)
            ->first();

        $this->assertNotNull($score);
        // positive direction: 0.75 * 100 = 75.0
        $this->assertEquals(75.0, (float) $score->score);
    }

    public function test_negative_direction_inverts_value(): void
    {
        $indicator = Indicator::query()->create([
            'slug' => 'test_crime',
            'name' => 'Test Crime',
            'source' => 'test',
            'direction' => 'negative',
            'weight' => 1.0,
            'is_active' => true,
        ]);

        IndicatorValue::query()->create([
            'deso_code' => '0180C0001',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 50,
            'normalized_value' => 0.80, // 80th percentile for crime
        ]);

        $this->service->computeScores(2024);

        $score = CompositeScore::query()
            ->where('deso_code', '0180C0001')
            ->first();

        // negative: 1.0 - 0.80 = 0.20 * 100 = 20.0
        $this->assertEquals(20.0, (float) $score->score);
    }

    public function test_neutral_direction_excluded_from_scoring(): void
    {
        $positiveIndicator = Indicator::query()->create([
            'slug' => 'test_income',
            'name' => 'Income',
            'source' => 'test',
            'direction' => 'positive',
            'weight' => 0.5,
            'is_active' => true,
        ]);

        $neutralIndicator = Indicator::query()->create([
            'slug' => 'test_pop',
            'name' => 'Population',
            'source' => 'test',
            'direction' => 'neutral',
            'weight' => 0.0,
            'is_active' => true,
        ]);

        IndicatorValue::query()->create([
            'deso_code' => '0180C0001',
            'indicator_id' => $positiveIndicator->id,
            'year' => 2024,
            'raw_value' => 300000,
            'normalized_value' => 0.60,
        ]);

        IndicatorValue::query()->create([
            'deso_code' => '0180C0001',
            'indicator_id' => $neutralIndicator->id,
            'year' => 2024,
            'raw_value' => 1500,
            'normalized_value' => 0.30,
        ]);

        $this->service->computeScores(2024);

        $score = CompositeScore::query()
            ->where('deso_code', '0180C0001')
            ->first();

        // Only positive indicator should count: 0.60 * 100 = 60
        $this->assertEquals(60.0, (float) $score->score);
    }

    public function test_weighted_sum_with_multiple_indicators(): void
    {
        $income = Indicator::query()->create([
            'slug' => 'income',
            'name' => 'Income',
            'source' => 'test',
            'direction' => 'positive',
            'weight' => 0.6,
            'is_active' => true,
        ]);

        $crime = Indicator::query()->create([
            'slug' => 'crime',
            'name' => 'Crime',
            'source' => 'test',
            'direction' => 'negative',
            'weight' => 0.4,
            'is_active' => true,
        ]);

        IndicatorValue::query()->create([
            'deso_code' => '0180C0001',
            'indicator_id' => $income->id,
            'year' => 2024,
            'raw_value' => 400000,
            'normalized_value' => 0.80,
        ]);

        IndicatorValue::query()->create([
            'deso_code' => '0180C0001',
            'indicator_id' => $crime->id,
            'year' => 2024,
            'raw_value' => 20,
            'normalized_value' => 0.30, // 30th percentile crime
        ]);

        $this->service->computeScores(2024);

        $score = CompositeScore::query()
            ->where('deso_code', '0180C0001')
            ->first();

        // income: positive, 0.80, weight 0.6 -> 0.6 * 0.80 = 0.48
        // crime: negative, 1.0-0.30 = 0.70, weight 0.4 -> 0.4 * 0.70 = 0.28
        // total = (0.48 + 0.28) / (0.6 + 0.4) * 100 = 76.0
        $this->assertEquals(76.0, (float) $score->score);
    }

    public function test_top_positive_and_negative_factors(): void
    {
        $income = Indicator::query()->create([
            'slug' => 'high_income',
            'name' => 'High Income',
            'source' => 'test',
            'direction' => 'positive',
            'weight' => 0.5,
            'is_active' => true,
        ]);

        $employment = Indicator::query()->create([
            'slug' => 'low_employment',
            'name' => 'Low Employment',
            'source' => 'test',
            'direction' => 'positive',
            'weight' => 0.5,
            'is_active' => true,
        ]);

        IndicatorValue::query()->create([
            'deso_code' => '0180C0001',
            'indicator_id' => $income->id,
            'year' => 2024,
            'raw_value' => 500000,
            'normalized_value' => 0.90, // High -> directed 0.90 > 0.7 => top_positive
        ]);

        IndicatorValue::query()->create([
            'deso_code' => '0180C0001',
            'indicator_id' => $employment->id,
            'year' => 2024,
            'raw_value' => 40,
            'normalized_value' => 0.20, // Low -> directed 0.20 < 0.3 => top_negative
        ]);

        $this->service->computeScores(2024);

        $score = CompositeScore::query()
            ->where('deso_code', '0180C0001')
            ->first();

        $this->assertContains('high_income', $score->top_positive);
        $this->assertContains('low_employment', $score->top_negative);
    }
}
