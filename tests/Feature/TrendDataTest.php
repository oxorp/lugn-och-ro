<?php

namespace Tests\Feature;

use App\Models\DesoArea;
use App\Models\Indicator;
use App\Models\IndicatorTrend;
use App\Models\MethodologyChange;
use App\Services\TrendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrendDataTest extends TestCase
{
    use RefreshDatabase;

    private function createDesoWithIndicatorData(
        bool $trendEligible = true,
        ?string $desoCode = null,
    ): DesoArea {
        $deso = DesoArea::factory()->create([
            'deso_code' => $desoCode ?? '0180A0010',
            'trend_eligible' => $trendEligible,
        ]);

        return $deso;
    }

    private function createIndicator(
        string $slug = 'median_income',
        string $direction = 'positive',
    ): Indicator {
        return Indicator::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => ucfirst(str_replace('_', ' ', $slug)),
                'source' => 'scb',
                'direction' => $direction,
                'weight' => 0.10,
                'normalization' => 'rank_percentile',
                'normalization_scope' => 'national',
                'is_active' => true,
                'unit' => 'SEK',
            ]
        );
    }

    private function insertIndicatorValue(
        string $desoCode,
        int $indicatorId,
        int $year,
        ?float $rawValue,
        ?float $normalizedValue = null,
    ): void {
        DB::table('indicator_values')->insert([
            'deso_code' => $desoCode,
            'indicator_id' => $indicatorId,
            'year' => $year,
            'raw_value' => $rawValue,
            'normalized_value' => $normalizedValue ?? ($rawValue !== null ? 0.5 : null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_trend_service_computes_rising_trend(): void
    {
        $deso = $this->createDesoWithIndicatorData();
        $indicator = $this->createIndicator();

        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2022, 100000);
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2023, 105000);
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2024, 110000);

        $service = app(TrendService::class);
        $stats = $service->computeTrends(2022, 2024);

        $this->assertEquals(1, $stats['trends_computed']);

        $trend = IndicatorTrend::query()
            ->where('deso_code', $deso->deso_code)
            ->where('indicator_id', $indicator->id)
            ->first();

        $this->assertNotNull($trend);
        $this->assertEquals('rising', $trend->direction);
        $this->assertEquals(10000, $trend->absolute_change);
        $this->assertEqualsWithDelta(10.0, $trend->percent_change, 0.1);
        $this->assertEquals(3, $trend->data_points);
        $this->assertEquals(1.0, $trend->confidence);
    }

    public function test_trend_service_computes_falling_trend(): void
    {
        $deso = $this->createDesoWithIndicatorData();
        $indicator = $this->createIndicator();

        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2022, 110000);
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2023, 105000);
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2024, 100000);

        $service = app(TrendService::class);
        $service->computeTrends(2022, 2024);

        $trend = IndicatorTrend::query()
            ->where('deso_code', $deso->deso_code)
            ->where('indicator_id', $indicator->id)
            ->first();

        $this->assertNotNull($trend);
        $this->assertEquals('falling', $trend->direction);
        $this->assertLessThan(0, $trend->absolute_change);
    }

    public function test_trend_service_computes_stable_within_threshold(): void
    {
        $deso = $this->createDesoWithIndicatorData();
        $indicator = $this->createIndicator();

        // 1% change is within the 3% stable threshold
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2022, 100000);
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2023, 100500);
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2024, 101000);

        $service = app(TrendService::class);
        $service->computeTrends(2022, 2024);

        $trend = IndicatorTrend::query()
            ->where('deso_code', $deso->deso_code)
            ->where('indicator_id', $indicator->id)
            ->first();

        $this->assertNotNull($trend);
        $this->assertEquals('stable', $trend->direction);
    }

    public function test_trend_skips_non_eligible_deso(): void
    {
        $deso = $this->createDesoWithIndicatorData(trendEligible: false);
        $indicator = $this->createIndicator();

        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2022, 100000);
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2024, 200000);

        $service = app(TrendService::class);
        $stats = $service->computeTrends(2022, 2024);

        $this->assertEquals(0, $stats['trends_computed']);
        $this->assertDatabaseCount('indicator_trends', 0);
    }

    public function test_trend_skips_neutral_direction_indicators(): void
    {
        $deso = $this->createDesoWithIndicatorData();
        $indicator = $this->createIndicator('population', 'neutral');

        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2022, 1000);
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2024, 1200);

        $service = app(TrendService::class);
        $stats = $service->computeTrends(2022, 2024);

        $this->assertEquals(0, $stats['trends_computed']);
    }

    public function test_trend_skips_indicator_with_methodology_break(): void
    {
        $deso = $this->createDesoWithIndicatorData();
        $indicator = $this->createIndicator();

        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2022, 100000);
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2024, 200000);

        MethodologyChange::create([
            'source' => 'scb',
            'indicator_id' => $indicator->id,
            'year_affected' => 2023,
            'change_type' => 'definition',
            'description' => 'Test methodology break',
            'breaks_trend' => true,
        ]);

        $service = app(TrendService::class);
        $stats = $service->computeTrends(2022, 2024);

        $this->assertEquals(0, $stats['trends_computed']);
    }

    public function test_trend_confidence_reflects_missing_data_points(): void
    {
        $deso = $this->createDesoWithIndicatorData();
        $indicator = $this->createIndicator();

        // Only 2 of 3 expected years have data
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2022, 100000);
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2024, 115000);

        $service = app(TrendService::class);
        $service->computeTrends(2022, 2024);

        $trend = IndicatorTrend::query()
            ->where('deso_code', $deso->deso_code)
            ->where('indicator_id', $indicator->id)
            ->first();

        $this->assertNotNull($trend);
        $this->assertEquals(2, $trend->data_points);
        // 2/3 expected years = 0.67 confidence
        $this->assertEqualsWithDelta(0.67, $trend->confidence, 0.01);
    }

    public function test_indicators_api_returns_trend_for_eligible_deso(): void
    {
        $deso = $this->createDesoWithIndicatorData();
        $indicator = $this->createIndicator();

        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2024, 312300, 0.40);
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2022, 300000);
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2023, 305000);

        IndicatorTrend::create([
            'deso_code' => $deso->deso_code,
            'indicator_id' => $indicator->id,
            'base_year' => 2022,
            'end_year' => 2024,
            'data_points' => 3,
            'absolute_change' => 12300,
            'percent_change' => 4.10,
            'direction' => 'rising',
            'confidence' => 1.0,
        ]);

        $response = $this->getJson("/api/deso/{$deso->deso_code}/indicators?year=2024");

        $response->assertOk();
        $response->assertJsonPath('trend_eligible', true);
        $response->assertJsonPath('trend_meta.eligible', true);
        $response->assertJsonPath('trend_meta.period', '2022–2024');

        $indicators = $response->json('indicators');
        $income = collect($indicators)->firstWhere('slug', 'median_income');

        $this->assertNotNull($income);
        $this->assertNotNull($income['trend']);
        $this->assertEquals('rising', $income['trend']['direction']);
        $this->assertEquals(3, count($income['history']));
    }

    public function test_indicators_api_returns_no_trend_for_ineligible_deso(): void
    {
        $deso = $this->createDesoWithIndicatorData(trendEligible: false, desoCode: '1384C1252');
        $indicator = $this->createIndicator();

        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2024, 393500, 0.84);

        $response = $this->getJson("/api/deso/{$deso->deso_code}/indicators?year=2024");

        $response->assertOk();
        $response->assertJsonPath('trend_eligible', false);
        $response->assertJsonPath('trend_meta.eligible', false);
        $response->assertJsonPath('trend_meta.reason', 'Area boundaries changed in 2025 revision');

        $indicators = $response->json('indicators');
        $income = collect($indicators)->firstWhere('slug', 'median_income');

        $this->assertNotNull($income);
        $this->assertNull($income['trend']);
    }

    public function test_indicators_api_returns_404_for_unknown_deso(): void
    {
        $response = $this->getJson('/api/deso/9999X0000/indicators?year=2024');

        $response->assertNotFound();
    }

    public function test_trend_service_upserts_on_recompute(): void
    {
        $deso = $this->createDesoWithIndicatorData();
        $indicator = $this->createIndicator();

        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2022, 100000);
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2024, 110000);

        $service = app(TrendService::class);

        // First computation
        $service->computeTrends(2022, 2024);
        $this->assertDatabaseCount('indicator_trends', 1);

        // Second computation should upsert, not duplicate
        $service->computeTrends(2022, 2024);
        $this->assertDatabaseCount('indicator_trends', 1);
    }

    public function test_compute_trends_command_runs(): void
    {
        $deso = $this->createDesoWithIndicatorData();
        $indicator = $this->createIndicator();

        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2022, 100000);
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2024, 110000);

        $this->artisan('compute:trends', [
            '--base-year' => 2022,
            '--end-year' => 2024,
        ])->assertSuccessful();

        $this->assertDatabaseCount('indicator_trends', 1);
    }

    public function test_indicators_api_includes_history_values(): void
    {
        $deso = $this->createDesoWithIndicatorData();
        $indicator = $this->createIndicator();

        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2022, 300000);
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2023, 305000);
        $this->insertIndicatorValue($deso->deso_code, $indicator->id, 2024, 312300, 0.40);

        $response = $this->getJson("/api/deso/{$deso->deso_code}/indicators?year=2024");

        $response->assertOk();

        $indicators = $response->json('indicators');
        $income = collect($indicators)->firstWhere('slug', 'median_income');

        $this->assertCount(3, $income['history']);
        $this->assertEquals(2022, $income['history'][0]['year']);
        $this->assertEquals(300000, $income['history'][0]['value']);
        $this->assertEquals(2024, $income['history'][2]['year']);
    }
}
