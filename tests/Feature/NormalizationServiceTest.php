<?php

namespace Tests\Feature;

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
}
