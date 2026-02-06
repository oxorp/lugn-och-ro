<?php

namespace Tests\Feature;

use App\Models\CrimeStatistic;
use App\Models\DesoVulnerabilityMapping;
use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Models\NtuSurveyData;
use App\Models\VulnerabilityArea;
use App\Services\BraDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrimeDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function test_crime_statistics_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('crime_statistics'));
    }

    public function test_vulnerability_areas_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('vulnerability_areas'));
    }

    public function test_crime_events_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('crime_events'));
    }

    public function test_ntu_survey_data_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('ntu_survey_data'));
    }

    public function test_deso_vulnerability_mapping_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('deso_vulnerability_mapping'));
    }

    public function test_crime_events_table_is_empty(): void
    {
        $this->assertDatabaseCount('crime_events', 0);
    }

    public function test_crime_statistics_model_can_be_created(): void
    {
        CrimeStatistic::query()->create([
            'municipality_code' => '0180',
            'municipality_name' => 'Stockholm',
            'year' => 2024,
            'crime_category' => 'crime_total',
            'reported_count' => 50000,
            'rate_per_100k' => 15000.00,
            'population' => 333333,
            'data_source' => 'test',
        ]);

        $this->assertDatabaseHas('crime_statistics', [
            'municipality_code' => '0180',
            'crime_category' => 'crime_total',
        ]);
    }

    public function test_crime_statistics_unique_constraint(): void
    {
        CrimeStatistic::query()->create([
            'municipality_code' => '0180',
            'year' => 2024,
            'crime_category' => 'crime_total',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        CrimeStatistic::query()->create([
            'municipality_code' => '0180',
            'year' => 2024,
            'crime_category' => 'crime_total',
        ]);
    }

    public function test_vulnerability_area_model_can_be_created(): void
    {
        $area = VulnerabilityArea::query()->create([
            'name' => 'Rinkeby',
            'tier' => 'sarskilt_utsatt',
            'police_region' => 'Stockholm',
            'local_police_area' => 'Järva',
            'assessment_year' => 2025,
            'is_current' => true,
        ]);

        $this->assertDatabaseHas('vulnerability_areas', [
            'name' => 'Rinkeby',
            'tier' => 'sarskilt_utsatt',
        ]);
        $this->assertTrue($area->is_current);
    }

    public function test_ntu_survey_data_model_can_be_created(): void
    {
        NtuSurveyData::query()->create([
            'area_code' => '01',
            'area_type' => 'lan',
            'area_name' => 'Stockholms län',
            'survey_year' => 2025,
            'reference_year' => 2024,
            'indicator_slug' => 'ntu_unsafe_night',
            'value' => 27.5,
            'data_source' => 'test',
        ]);

        $this->assertDatabaseHas('ntu_survey_data', [
            'area_code' => '01',
            'indicator_slug' => 'ntu_unsafe_night',
        ]);
    }

    public function test_deso_vulnerability_mapping_with_relationship(): void
    {
        $area = VulnerabilityArea::query()->create([
            'name' => 'Rosengård',
            'tier' => 'sarskilt_utsatt',
            'assessment_year' => 2025,
            'is_current' => true,
        ]);

        $mapping = DesoVulnerabilityMapping::query()->create([
            'deso_code' => '1280C1800',
            'vulnerability_area_id' => $area->id,
            'overlap_fraction' => 0.85,
            'tier' => 'sarskilt_utsatt',
        ]);

        $this->assertEquals('Rosengård', $mapping->vulnerabilityArea->name);
        $this->assertEquals('sarskilt_utsatt', $mapping->tier);
    }

    public function test_vulnerability_area_has_deso_mappings(): void
    {
        $area = VulnerabilityArea::query()->create([
            'name' => 'Hammarkullen',
            'tier' => 'utsatt',
            'assessment_year' => 2025,
            'is_current' => true,
        ]);

        DesoVulnerabilityMapping::query()->create([
            'deso_code' => '1480C3600',
            'vulnerability_area_id' => $area->id,
            'overlap_fraction' => 0.95,
            'tier' => 'utsatt',
        ]);

        DesoVulnerabilityMapping::query()->create([
            'deso_code' => '1480C3610',
            'vulnerability_area_id' => $area->id,
            'overlap_fraction' => 0.30,
            'tier' => 'utsatt',
        ]);

        $this->assertCount(2, $area->desoMappings);
    }

    public function test_bra_data_service_parses_kommun_csv(): void
    {
        $csvContent = "\xEF\xBB\xBF"."Kommun;Antal (prel.);Per 100 000 inv. (prel.)\nStockholm;95000;19108\nMalmö;45000;14500\n";
        $tmpFile = tempnam(sys_get_temp_dir(), 'bra_test_');
        file_put_contents($tmpFile, $csvContent);

        $service = new BraDataService;
        $data = $service->parseKommunCsv($tmpFile);

        $this->assertCount(2, $data);
        $this->assertEquals('Stockholm', $data[0]['municipality_name']);
        $this->assertEquals(95000, $data[0]['reported_count']);
        $this->assertEquals(19108, $data[0]['rate_per_100k']);

        unlink($tmpFile);
    }

    public function test_bra_data_service_handles_suppressed_values(): void
    {
        $csvContent = "Kommun;Antal (prel.);Per 100 000 inv. (prel.)\nTestKommun;..;..\n";
        $tmpFile = tempnam(sys_get_temp_dir(), 'bra_test_');
        file_put_contents($tmpFile, $csvContent);

        $service = new BraDataService;
        $data = $service->parseKommunCsv($tmpFile);

        $this->assertCount(0, $data);

        unlink($tmpFile);
    }

    public function test_bra_data_service_estimates_category_rates(): void
    {
        $service = new BraDataService;
        $proportions = $service->getNationalProportions(2024);

        $this->assertNotEmpty($proportions);
        $this->assertArrayHasKey('crime_person', $proportions);
        $this->assertArrayHasKey('crime_theft', $proportions);

        // Sum should be < 1.0 (not all categories covered)
        $this->assertLessThan(1.0, array_sum($proportions));

        $rates = $service->estimateCategoryRates(10000.0, $proportions);
        $this->assertArrayHasKey('crime_violent_rate', $rates);
        $this->assertArrayHasKey('crime_property_rate', $rates);
        $this->assertGreaterThan(0, $rates['crime_violent_rate']);
    }

    public function test_crime_api_endpoint_returns_404_for_unknown_deso(): void
    {
        $response = $this->getJson('/api/deso/UNKNOWN/crime?year=2024');

        $response->assertNotFound();
    }

    public function test_crime_api_endpoint_returns_crime_data(): void
    {
        $this->insertDesoWithGeometry('0180C6250', '0180', '01', 'Stockholm');

        $indicator = Indicator::query()->create([
            'slug' => 'crime_violent_rate',
            'name' => 'Violent Crime Rate',
            'source' => 'bra',
            'unit' => 'per_100k',
            'direction' => 'negative',
            'weight' => 0.08,
            'normalization' => 'rank_percentile',
            'is_active' => true,
            'category' => 'crime',
        ]);

        IndicatorValue::query()->create([
            'deso_code' => '0180C6250',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 5000.0,
            'normalized_value' => 0.85,
        ]);

        CrimeStatistic::query()->create([
            'municipality_code' => '0180',
            'municipality_name' => 'Stockholm',
            'year' => 2024,
            'crime_category' => 'crime_total',
            'rate_per_100k' => 19108.00,
        ]);

        $response = $this->getJson('/api/deso/0180C6250/crime?year=2024');

        $response->assertOk();
        $response->assertJsonStructure([
            'deso_code',
            'kommun_code',
            'kommun_name',
            'year',
            'estimated_rates' => [
                'violent' => ['rate', 'percentile'],
                'property' => ['rate', 'percentile'],
                'total' => ['rate', 'percentile'],
            ],
            'perceived_safety',
            'kommun_actual_rates',
            'vulnerability',
        ]);
        $response->assertJsonPath('deso_code', '0180C6250');
        $response->assertJsonPath('kommun_name', 'Stockholm');
        $response->assertJsonPath('estimated_rates.violent.rate', 5000);
    }

    public function test_crime_api_endpoint_includes_vulnerability_info(): void
    {
        $this->insertDesoWithGeometry('1280C1800', '1280', '12', 'Malmö');

        $area = VulnerabilityArea::query()->create([
            'name' => 'Rosengård',
            'tier' => 'sarskilt_utsatt',
            'police_region' => 'Syd',
            'assessment_year' => 2025,
            'is_current' => true,
        ]);

        DesoVulnerabilityMapping::query()->create([
            'deso_code' => '1280C1800',
            'vulnerability_area_id' => $area->id,
            'overlap_fraction' => 0.85,
            'tier' => 'sarskilt_utsatt',
        ]);

        $response = $this->getJson('/api/deso/1280C1800/crime?year=2024');

        $response->assertOk();
        $response->assertJsonPath('vulnerability.name', 'Rosengård');
        $response->assertJsonPath('vulnerability.tier', 'sarskilt_utsatt');
        $response->assertJsonPath('vulnerability.tier_label', 'Särskilt utsatt område');
    }

    public function test_crime_api_excludes_low_overlap_vulnerability(): void
    {
        $this->insertDesoWithGeometry('0114A0010', '0114', '01', 'Upplands Väsby');

        $area = VulnerabilityArea::query()->create([
            'name' => 'SomeArea',
            'tier' => 'utsatt',
            'assessment_year' => 2025,
            'is_current' => true,
        ]);

        DesoVulnerabilityMapping::query()->create([
            'deso_code' => '0114A0010',
            'vulnerability_area_id' => $area->id,
            'overlap_fraction' => 0.10, // Below 25% threshold
            'tier' => 'utsatt',
        ]);

        $response = $this->getJson('/api/deso/0114A0010/crime?year=2024');

        $response->assertOk();
        $response->assertJsonPath('vulnerability', null);
    }

    public function test_crime_indicator_seeder_creates_indicators(): void
    {
        $this->artisan('db:seed', ['--class' => 'CrimeIndicatorSeeder']);

        $this->assertDatabaseHas('indicators', ['slug' => 'crime_violent_rate', 'category' => 'crime']);
        $this->assertDatabaseHas('indicators', ['slug' => 'crime_property_rate', 'category' => 'crime']);
        $this->assertDatabaseHas('indicators', ['slug' => 'crime_total_rate', 'category' => 'crime']);
        $this->assertDatabaseHas('indicators', ['slug' => 'perceived_safety', 'category' => 'safety']);
        $this->assertDatabaseHas('indicators', ['slug' => 'vulnerability_flag', 'category' => 'crime']);
    }

    public function test_crime_indicator_seeder_rebalances_weights(): void
    {
        // Create existing indicators first
        Indicator::query()->create(['slug' => 'median_income', 'name' => 'Median Income', 'source' => 'scb', 'unit' => 'sek', 'direction' => 'positive', 'weight' => 0.12, 'normalization' => 'rank_percentile', 'is_active' => true, 'category' => 'income']);
        Indicator::query()->create(['slug' => 'employment_rate', 'name' => 'Employment Rate', 'source' => 'scb', 'unit' => 'percent', 'direction' => 'positive', 'weight' => 0.10, 'normalization' => 'rank_percentile', 'is_active' => true, 'category' => 'employment']);

        $this->artisan('db:seed', ['--class' => 'CrimeIndicatorSeeder']);

        // Old weights should be rebalanced
        $this->assertDatabaseHas('indicators', ['slug' => 'median_income', 'weight' => '0.0900']);
        $this->assertDatabaseHas('indicators', ['slug' => 'employment_rate', 'weight' => '0.0800']);
        // New crime indicator exists
        $this->assertDatabaseHas('indicators', ['slug' => 'vulnerability_flag', 'weight' => '0.1000']);
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
