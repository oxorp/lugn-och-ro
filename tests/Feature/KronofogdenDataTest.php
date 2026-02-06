<?php

namespace Tests\Feature;

use App\Models\DebtDisaggregationResult;
use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Models\KronofogdenStatistic;
use App\Services\KronofogdenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KronofogdenDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function test_kronofogden_statistics_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('kronofogden_statistics'));
    }

    public function test_debt_disaggregation_results_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('debt_disaggregation_results'));
    }

    public function test_disaggregation_models_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('disaggregation_models'));
    }

    public function test_kronofogden_statistic_model_can_be_created(): void
    {
        KronofogdenStatistic::query()->create([
            'municipality_code' => '0180',
            'municipality_name' => 'Stockholm',
            'county_code' => '01',
            'year' => 2024,
            'indebted_pct' => 3.59,
            'median_debt_sek' => 93530,
            'eviction_rate_per_100k' => 26.23,
            'data_source' => 'test',
        ]);

        $this->assertDatabaseHas('kronofogden_statistics', [
            'municipality_code' => '0180',
            'indebted_pct' => '3.59',
        ]);
    }

    public function test_kronofogden_unique_constraint(): void
    {
        KronofogdenStatistic::query()->create([
            'municipality_code' => '0180',
            'year' => 2024,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        KronofogdenStatistic::query()->create([
            'municipality_code' => '0180',
            'year' => 2024,
        ]);
    }

    public function test_debt_disaggregation_result_model_can_be_created(): void
    {
        DebtDisaggregationResult::query()->create([
            'deso_code' => '0180C6250',
            'year' => 2024,
            'municipality_code' => '0180',
            'estimated_debt_rate' => 5.12,
            'estimated_eviction_rate' => 32.45,
            'propensity_weight' => 0.123456,
            'is_constrained' => true,
            'model_version' => 'v1_weighted',
        ]);

        $this->assertDatabaseHas('debt_disaggregation_results', [
            'deso_code' => '0180C6250',
            'estimated_debt_rate' => '5.120',
        ]);
    }

    public function test_debt_disaggregation_result_unique_constraint(): void
    {
        DebtDisaggregationResult::query()->create([
            'deso_code' => '0180C6250',
            'year' => 2024,
            'municipality_code' => '0180',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DebtDisaggregationResult::query()->create([
            'deso_code' => '0180C6250',
            'year' => 2024,
            'municipality_code' => '0180',
        ]);
    }

    public function test_kronofogden_service_filters_kommun_codes(): void
    {
        Http::fake([
            '*/municipality' => Http::response([
                'values' => [
                    ['id' => '0000', 'title' => 'Riket', 'type' => 'K'],
                    ['id' => '0001', 'title' => 'Region Stockholm', 'type' => 'L'],
                    ['id' => '0180', 'title' => 'Stockholm', 'type' => 'K'],
                    ['id' => '1280', 'title' => 'Malmö', 'type' => 'K'],
                ],
            ]),
            '*/kpi/N00989/*' => Http::response([
                'values' => [
                    [
                        'municipality' => '0000',
                        'period' => 2024,
                        'values' => [['gender' => 'T', 'value' => 4.1]],
                    ],
                    [
                        'municipality' => '0001',
                        'period' => 2024,
                        'values' => [['gender' => 'T', 'value' => 3.8]],
                    ],
                    [
                        'municipality' => '0180',
                        'period' => 2024,
                        'values' => [
                            ['gender' => 'T', 'value' => 3.59],
                            ['gender' => 'M', 'value' => 5.1],
                            ['gender' => 'K', 'value' => 2.2],
                        ],
                    ],
                    [
                        'municipality' => '1280',
                        'period' => 2024,
                        'values' => [['gender' => 'T', 'value' => 5.8]],
                    ],
                ],
            ]),
            '*/kpi/N00990/*' => Http::response(['values' => []]),
            '*/kpi/U00958/*' => Http::response(['values' => []]),
        ]);

        $service = new KronofogdenService;
        $data = $service->fetchFromKolada(2024);

        // Should only have 2 kommuner (0180 and 1280), not 0000 (Riket) or 0001 (Region)
        $this->assertCount(2, $data);
        $this->assertArrayHasKey('0180', $data->toArray());
        $this->assertArrayHasKey('1280', $data->toArray());
        $this->assertEquals(3.59, $data['0180']['indebted_pct']);
        $this->assertEquals(5.1, $data['0180']['indebted_men_pct']);
        $this->assertEquals(2.2, $data['0180']['indebted_women_pct']);
    }

    public function test_kronofogden_service_handles_api_failure(): void
    {
        Http::fake([
            '*/municipality' => Http::response(['values' => []], 200),
            '*/kpi/*' => Http::response('', 500),
        ]);

        $service = new KronofogdenService;
        $data = $service->fetchFromKolada(2024);

        $this->assertCount(0, $data);
    }

    public function test_kronofogden_indicator_seeder_creates_indicators(): void
    {
        $this->artisan('db:seed', ['--class' => 'KronofogdenIndicatorSeeder']);

        $this->assertDatabaseHas('indicators', ['slug' => 'debt_rate_pct', 'category' => 'financial_distress']);
        $this->assertDatabaseHas('indicators', ['slug' => 'eviction_rate', 'category' => 'financial_distress']);
        $this->assertDatabaseHas('indicators', ['slug' => 'median_debt_sek', 'category' => 'financial_distress']);
    }

    public function test_kronofogden_indicator_seeder_rebalances_weights(): void
    {
        Indicator::query()->create(['slug' => 'median_income', 'name' => 'Median Income', 'source' => 'scb', 'unit' => 'sek', 'direction' => 'positive', 'weight' => 0.12, 'normalization' => 'rank_percentile', 'is_active' => true, 'category' => 'income']);
        Indicator::query()->create(['slug' => 'crime_violent_rate', 'name' => 'Violent Crime Rate', 'source' => 'bra', 'unit' => 'per_100k', 'direction' => 'negative', 'weight' => 0.08, 'normalization' => 'rank_percentile', 'is_active' => true, 'category' => 'crime']);

        $this->artisan('db:seed', ['--class' => 'KronofogdenIndicatorSeeder']);

        $this->assertDatabaseHas('indicators', ['slug' => 'median_income', 'weight' => '0.0750']);
        $this->assertDatabaseHas('indicators', ['slug' => 'crime_violent_rate', 'weight' => '0.0700']);
        $this->assertDatabaseHas('indicators', ['slug' => 'debt_rate_pct', 'weight' => '0.0600']);
    }

    public function test_financial_api_endpoint_returns_404_for_unknown_deso(): void
    {
        $response = $this->getJson('/api/deso/UNKNOWN/financial?year=2024');

        $response->assertOk();
        $response->assertJsonPath('estimated_debt_rate', null);
    }

    public function test_financial_api_endpoint_returns_data(): void
    {
        $this->insertDesoWithGeometry('0180C6250', '0180', '01', 'Stockholm');

        KronofogdenStatistic::query()->create([
            'municipality_code' => '0180',
            'municipality_name' => 'Stockholm',
            'year' => 2024,
            'indebted_pct' => 3.59,
            'median_debt_sek' => 93530,
            'eviction_rate_per_100k' => 26.23,
            'data_source' => 'kolada',
        ]);

        DebtDisaggregationResult::query()->create([
            'deso_code' => '0180C6250',
            'year' => 2024,
            'municipality_code' => '0180',
            'estimated_debt_rate' => 5.12,
            'estimated_eviction_rate' => 32.45,
            'propensity_weight' => 0.123456,
            'is_constrained' => true,
            'model_version' => 'v1_weighted',
        ]);

        $response = $this->getJson('/api/deso/0180C6250/financial?year=2024');

        $response->assertOk();
        $response->assertJsonStructure([
            'deso_code',
            'year',
            'estimated_debt_rate',
            'estimated_eviction_rate',
            'kommun_actual_rate',
            'kommun_name',
            'kommun_median_debt',
            'national_avg_rate',
            'is_high_distress',
            'is_estimated',
        ]);
        $response->assertJsonPath('deso_code', '0180C6250');
        $response->assertJsonPath('estimated_debt_rate', 5.12);
        $response->assertJsonPath('kommun_actual_rate', 3.59);
        $response->assertJsonPath('kommun_name', 'Stockholm');
        $response->assertJsonPath('is_estimated', true);
    }

    public function test_financial_api_detects_high_distress(): void
    {
        $this->insertDesoWithGeometry('0180C6250', '0180', '01', 'Stockholm');

        // National avg will be 4.0 (only one kommun)
        KronofogdenStatistic::query()->create([
            'municipality_code' => '0180',
            'municipality_name' => 'Stockholm',
            'year' => 2024,
            'indebted_pct' => 4.00,
            'data_source' => 'kolada',
        ]);

        // DeSO rate is 9.0 — well above 2x national avg (8.0)
        DebtDisaggregationResult::query()->create([
            'deso_code' => '0180C6250',
            'year' => 2024,
            'municipality_code' => '0180',
            'estimated_debt_rate' => 9.00,
            'is_constrained' => true,
        ]);

        $response = $this->getJson('/api/deso/0180C6250/financial?year=2024');

        $response->assertOk();
        $response->assertJsonPath('is_high_distress', true);
    }

    public function test_financial_api_not_high_distress_for_low_rate(): void
    {
        $this->insertDesoWithGeometry('1262C1080', '1262', '12', 'Lomma');

        KronofogdenStatistic::query()->create([
            'municipality_code' => '1262',
            'municipality_name' => 'Lomma',
            'year' => 2024,
            'indebted_pct' => 0.93,
            'data_source' => 'kolada',
        ]);

        DebtDisaggregationResult::query()->create([
            'deso_code' => '1262C1080',
            'year' => 2024,
            'municipality_code' => '1262',
            'estimated_debt_rate' => 0.90,
            'is_constrained' => true,
        ]);

        $response = $this->getJson('/api/deso/1262C1080/financial?year=2024');

        $response->assertOk();
        $response->assertJsonPath('is_high_distress', false);
    }

    public function test_aggregate_kronofogden_indicators_command(): void
    {
        $this->insertDesoWithGeometry('0180C6250', '0180', '01', 'Stockholm');

        $this->artisan('db:seed', ['--class' => 'KronofogdenIndicatorSeeder']);

        KronofogdenStatistic::query()->create([
            'municipality_code' => '0180',
            'municipality_name' => 'Stockholm',
            'year' => 2024,
            'indebted_pct' => 3.59,
            'median_debt_sek' => 93530,
            'eviction_rate_per_100k' => 26.23,
            'data_source' => 'kolada',
        ]);

        DebtDisaggregationResult::query()->create([
            'deso_code' => '0180C6250',
            'year' => 2024,
            'municipality_code' => '0180',
            'estimated_debt_rate' => 5.12,
            'estimated_eviction_rate' => 32.45,
            'is_constrained' => true,
        ]);

        $this->artisan('aggregate:kronofogden-indicators', ['--year' => 2024])
            ->assertSuccessful();

        $debtIndicator = Indicator::query()->where('slug', 'debt_rate_pct')->first();
        $this->assertNotNull($debtIndicator);

        $this->assertDatabaseHas('indicator_values', [
            'deso_code' => '0180C6250',
            'indicator_id' => $debtIndicator->id,
            'year' => 2024,
        ]);

        $value = IndicatorValue::query()
            ->where('deso_code', '0180C6250')
            ->where('indicator_id', $debtIndicator->id)
            ->first();
        $this->assertEquals(5.12, round((float) $value->raw_value, 2));
    }

    private function insertDesoWithGeometry(string $desoCode, string $kommunCode, string $lanCode, string $kommunName): void
    {
        DB::statement("
            INSERT INTO deso_areas (deso_code, kommun_code, kommun_name, lan_code, geom, area_km2, created_at, updated_at)
            VALUES (
                :deso_code, :kommun_code, :kommun_name, :lan_code,
                ST_Multi(ST_SetSRID(ST_GeomFromText('POLYGON((18.0 59.3, 18.1 59.3, 18.1 59.4, 18.0 59.4, 18.0 59.3))'), 4326)),
                1.5, NOW(), NOW()
            )
        ", [
            'deso_code' => $desoCode,
            'kommun_code' => $kommunCode,
            'kommun_name' => $kommunName,
            'lan_code' => $lanCode,
        ]);
    }
}
