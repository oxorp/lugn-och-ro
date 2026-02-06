<?php

namespace Tests\Feature;

use App\Models\DesoArea;
use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Services\NormalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NormalizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NormalizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NormalizationService;
    }

    public function test_rank_percentile_normalizes_to_zero_one_range(): void
    {
        $indicator = Indicator::query()->create([
            'slug' => 'test_income',
            'name' => 'Test Income',
            'source' => 'test',
            'direction' => 'positive',
            'normalization' => 'rank_percentile',
        ]);

        foreach ([100, 200, 300, 400, 500] as $i => $value) {
            IndicatorValue::query()->create([
                'deso_code' => sprintf('0180C%04d', $i + 1),
                'indicator_id' => $indicator->id,
                'year' => 2024,
                'raw_value' => $value,
            ]);
        }

        $this->service->normalizeIndicator($indicator, 2024);

        $values = IndicatorValue::query()
            ->where('indicator_id', $indicator->id)
            ->orderBy('raw_value')
            ->pluck('normalized_value')
            ->toArray();

        // First should be 0.0 (lowest), last should be 1.0 (highest)
        $this->assertEquals(0.0, (float) $values[0]);
        $this->assertEquals(1.0, (float) $values[4]);

        // All values should be in [0, 1]
        foreach ($values as $v) {
            $this->assertGreaterThanOrEqual(0.0, (float) $v);
            $this->assertLessThanOrEqual(1.0, (float) $v);
        }
    }

    public function test_normalization_handles_ties(): void
    {
        $indicator = Indicator::query()->create([
            'slug' => 'test_tied',
            'name' => 'Test Tied',
            'source' => 'test',
            'direction' => 'positive',
            'normalization' => 'rank_percentile',
        ]);

        foreach ([100, 100, 200, 200] as $i => $value) {
            IndicatorValue::query()->create([
                'deso_code' => sprintf('0180C%04d', $i + 1),
                'indicator_id' => $indicator->id,
                'year' => 2024,
                'raw_value' => $value,
            ]);
        }

        $this->service->normalizeIndicator($indicator, 2024);

        $grouped = IndicatorValue::query()
            ->where('indicator_id', $indicator->id)
            ->orderBy('raw_value')
            ->pluck('normalized_value')
            ->toArray();

        // Tied values should get the same normalized value
        $this->assertEquals($grouped[0], $grouped[1]);
        $this->assertEquals($grouped[2], $grouped[3]);
    }

    public function test_normalize_all_processes_active_indicators(): void
    {
        $active = Indicator::query()->create([
            'slug' => 'test_active',
            'name' => 'Active',
            'source' => 'test',
            'direction' => 'positive',
            'is_active' => true,
            'normalization' => 'rank_percentile',
        ]);

        $inactive = Indicator::query()->create([
            'slug' => 'test_inactive',
            'name' => 'Inactive',
            'source' => 'test',
            'direction' => 'positive',
            'is_active' => false,
            'normalization' => 'rank_percentile',
        ]);

        IndicatorValue::query()->create([
            'deso_code' => '0180C0001',
            'indicator_id' => $active->id,
            'year' => 2024,
            'raw_value' => 100,
        ]);

        IndicatorValue::query()->create([
            'deso_code' => '0180C0001',
            'indicator_id' => $inactive->id,
            'year' => 2024,
            'raw_value' => 100,
        ]);

        $this->service->normalizeAll(2024);

        // Active should be normalized (only 1 value, so PERCENT_RANK = 0)
        $activeValue = IndicatorValue::query()
            ->where('indicator_id', $active->id)
            ->first();
        $this->assertNotNull($activeValue->normalized_value);

        // Inactive should NOT be normalized
        $inactiveValue = IndicatorValue::query()
            ->where('indicator_id', $inactive->id)
            ->first();
        $this->assertNull($inactiveValue->normalized_value);
    }

    public function test_stratified_normalization_ranks_within_tiers(): void
    {
        // Create 35 DeSOs per tier to exceed the minimum tier size (30)
        $count = 35;
        $urbanDesos = [];
        $ruralDesos = [];
        for ($i = 1; $i <= $count; $i++) {
            $urbanDesos[] = DesoArea::factory()->create([
                'deso_code' => sprintf('0180C%04d', $i),
                'urbanity_tier' => 'urban',
            ]);
            $ruralDesos[] = DesoArea::factory()->create([
                'deso_code' => sprintf('2580C%04d', $i),
                'urbanity_tier' => 'rural',
            ]);
        }

        $indicator = Indicator::query()->create([
            'slug' => 'test_stratified',
            'name' => 'Test Stratified',
            'source' => 'test',
            'direction' => 'positive',
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'urbanity_stratified',
        ]);

        // Urban DeSOs: values 1000-1034 (high absolute values)
        for ($i = 0; $i < $count; $i++) {
            IndicatorValue::query()->create([
                'deso_code' => $urbanDesos[$i]->deso_code,
                'indicator_id' => $indicator->id,
                'year' => 2024,
                'raw_value' => 1000 + $i,
            ]);
        }

        // Rural DeSOs: values 10-44 (much lower absolute values)
        for ($i = 0; $i < $count; $i++) {
            IndicatorValue::query()->create([
                'deso_code' => $ruralDesos[$i]->deso_code,
                'indicator_id' => $indicator->id,
                'year' => 2024,
                'raw_value' => 10 + $i,
            ]);
        }

        $this->service->normalizeIndicator($indicator, 2024);

        // The highest in each tier should get 1.0 (ranked within its tier)
        $urbanMax = IndicatorValue::query()
            ->where('indicator_id', $indicator->id)
            ->where('deso_code', $urbanDesos[$count - 1]->deso_code)
            ->first();
        $this->assertEquals(1.0, (float) $urbanMax->normalized_value);

        $ruralMax = IndicatorValue::query()
            ->where('indicator_id', $indicator->id)
            ->where('deso_code', $ruralDesos[$count - 1]->deso_code)
            ->first();
        $this->assertEquals(1.0, (float) $ruralMax->normalized_value);

        // The lowest in each tier should get 0.0
        $urbanMin = IndicatorValue::query()
            ->where('indicator_id', $indicator->id)
            ->where('deso_code', $urbanDesos[0]->deso_code)
            ->first();
        $this->assertEquals(0.0, (float) $urbanMin->normalized_value);

        $ruralMin = IndicatorValue::query()
            ->where('indicator_id', $indicator->id)
            ->where('deso_code', $ruralDesos[0]->deso_code)
            ->first();
        $this->assertEquals(0.0, (float) $ruralMin->normalized_value);

        // Key test: rural max (raw=44) and urban min (raw=1000) should both
        // have tier-appropriate ranks, NOT national ranks.
        // If nationally ranked, rural max would be ~0.5 and urban min ~0.5
        // With stratification, rural max = 1.0 and urban min = 0.0
    }

    public function test_stratified_normalization_falls_back_for_null_tier(): void
    {
        // Create a DeSO without urbanity_tier
        DesoArea::factory()->create([
            'deso_code' => '0180C0001',
            'urbanity_tier' => null,
        ]);

        // And some with tiers
        DesoArea::factory()->create([
            'deso_code' => '0180C0002',
            'urbanity_tier' => 'urban',
        ]);
        DesoArea::factory()->create([
            'deso_code' => '0180C0003',
            'urbanity_tier' => 'urban',
        ]);

        $indicator = Indicator::query()->create([
            'slug' => 'test_null_tier',
            'name' => 'Test Null Tier',
            'source' => 'test',
            'direction' => 'positive',
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'urbanity_stratified',
        ]);

        IndicatorValue::query()->create([
            'deso_code' => '0180C0001',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 50,
        ]);
        IndicatorValue::query()->create([
            'deso_code' => '0180C0002',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 100,
        ]);
        IndicatorValue::query()->create([
            'deso_code' => '0180C0003',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 200,
        ]);

        $this->service->normalizeIndicator($indicator, 2024);

        // The null-tier DeSO should still get a normalized value (fallback to national)
        $nullTierValue = IndicatorValue::query()
            ->where('indicator_id', $indicator->id)
            ->where('deso_code', '0180C0001')
            ->first();
        $this->assertNotNull($nullTierValue->normalized_value);
    }

    public function test_national_scope_indicator_normalizes_nationally(): void
    {
        // Even with urbanity tiers set, national scope should rank globally
        DesoArea::factory()->create([
            'deso_code' => '0180C0001',
            'urbanity_tier' => 'urban',
        ]);
        DesoArea::factory()->create([
            'deso_code' => '2580C0001',
            'urbanity_tier' => 'rural',
        ]);

        $indicator = Indicator::query()->create([
            'slug' => 'test_national',
            'name' => 'Test National',
            'source' => 'test',
            'direction' => 'positive',
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
        ]);

        IndicatorValue::query()->create([
            'deso_code' => '0180C0001',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 100,
        ]);
        IndicatorValue::query()->create([
            'deso_code' => '2580C0001',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 200,
        ]);

        $this->service->normalizeIndicator($indicator, 2024);

        $lower = IndicatorValue::query()
            ->where('indicator_id', $indicator->id)
            ->where('deso_code', '0180C0001')
            ->first();
        $higher = IndicatorValue::query()
            ->where('indicator_id', $indicator->id)
            ->where('deso_code', '2580C0001')
            ->first();

        $this->assertEquals(0.0, (float) $lower->normalized_value);
        $this->assertEquals(1.0, (float) $higher->normalized_value);
    }
}
