<?php

namespace Tests\Feature;

use App\Models\DeSoCrosswalk;
use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Services\ScbApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IngestScbHistoricalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    // --- ScbApiService historical config tests ---

    public function test_historical_config_returns_current_table_for_income(): void
    {
        $service = new ScbApiService;
        $config = $service->getHistoricalConfig('median_income', 2019);

        $this->assertNotNull($config);
        $this->assertEquals('HE/HE0110/HE0110I/Tab3InkDesoRegso', $config['path']);
        $this->assertEquals('multiply_1000', $config['value_transform']);
    }

    public function test_historical_config_returns_old_table_for_education(): void
    {
        $service = new ScbApiService;
        $config = $service->getHistoricalConfig('education_post_secondary_pct', 2022);

        $this->assertNotNull($config);
        $this->assertEquals('UF/UF0506/UF0506D/UtbSUNBefDesoRegso', $config['path']);
        $this->assertEquals('000005MO', $config['contents_code']);
        $this->assertEquals('education_post_secondary', $config['value_transform']);
    }

    public function test_historical_config_returns_old_table_for_rental(): void
    {
        $service = new ScbApiService;
        $config = $service->getHistoricalConfig('rental_tenure_pct', 2020);

        $this->assertNotNull($config);
        $this->assertEquals('BO/BO0104/BO0104X/BO0104T10N', $config['path']);
        $this->assertEquals('000006OC', $config['contents_code']);
        $this->assertEquals('ratio_first_over_sum', $config['value_transform']);
    }

    public function test_historical_config_returns_am0207_for_employment_2019(): void
    {
        $service = new ScbApiService;
        $config = $service->getHistoricalConfig('employment_rate', 2019);

        $this->assertNotNull($config);
        $this->assertStringContainsString('AM0207', $config['path']);
    }

    public function test_historical_config_returns_am0210_for_employment_2022(): void
    {
        $service = new ScbApiService;
        $config = $service->getHistoricalConfig('employment_rate', 2022);

        $this->assertNotNull($config);
        $this->assertStringContainsString('AM0210', $config['path']);
        $this->assertIsArray($config['contents_code']);
        $this->assertCount(2, $config['contents_code']);
    }

    public function test_historical_config_returns_null_for_unavailable_year(): void
    {
        $service = new ScbApiService;

        // Education old table starts at 2015
        $config = $service->getHistoricalConfig('education_post_secondary_pct', 2014);
        $this->assertNull($config);
    }

    public function test_get_historical_years_returns_correct_range(): void
    {
        $service = new ScbApiService;

        $incomeYears = $service->getHistoricalYears('median_income');
        $this->assertContains(2019, $incomeYears);
        $this->assertContains(2023, $incomeYears);
        $this->assertNotContains(2024, $incomeYears);

        $educationYears = $service->getHistoricalYears('education_post_secondary_pct');
        $this->assertContains(2019, $educationYears);
        $this->assertContains(2023, $educationYears);
        $this->assertNotContains(2014, $educationYears);

        $employmentYears = $service->getHistoricalYears('employment_rate');
        $this->assertContains(2019, $employmentYears);
        $this->assertContains(2023, $employmentYears);
    }

    // --- fetchHistorical with HTTP faking ---

    public function test_fetch_historical_income_applies_multiply_1000(): void
    {
        Http::fake([
            'api.scb.se/*' => Http::response($this->makeSimpleResponse('000008AB', [
                '0180A0010' => 325.5,
                '0180A0020' => 198.2,
            ]), 200),
        ]);

        $service = new ScbApiService;
        $result = $service->fetchHistorical('median_income', 2019);

        $this->assertArrayHasKey('0180A0010', $result);
        $this->assertEquals(325500, $result['0180A0010']);
        $this->assertEquals(198200, $result['0180A0020']);
    }

    public function test_fetch_historical_education_uses_old_table(): void
    {
        Http::fake([
            'api.scb.se/*/UF/UF0506/UF0506D/UtbSUNBefDesoRegso' => Http::response(
                $this->makeEducationResponse(['0180A0010']),
                200
            ),
        ]);

        $service = new ScbApiService;
        $result = $service->fetchHistorical('education_post_secondary_pct', 2022);

        $this->assertArrayHasKey('0180A0010', $result);
        $this->assertIsFloat($result['0180A0010']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'UtbSUNBefDesoRegso')
                && ! str_contains($request->url(), 'UtbSUNBefDesoRegsoN');
        });
    }

    public function test_fetch_historical_employment_am0210_computes_ratio(): void
    {
        // AM0210 response with two contents codes: employed and total
        $response = [
            'id' => ['Region', 'Kon', 'Alder', 'ContentsCode', 'Tid'],
            'size' => [2, 1, 1, 2, 1],
            'value' => [
                // DeSO 1: employed=800, total=1000
                800, 1000,
                // DeSO 2: employed=400, total=600
                400, 600,
            ],
            'dimension' => [
                'Region' => [
                    'category' => [
                        'index' => ['0180A0010' => 0, '0180A0020' => 1],
                    ],
                ],
                'Kon' => ['category' => ['index' => ['1+2' => 0]]],
                'Alder' => ['category' => ['index' => ['20-64' => 0]]],
                'ContentsCode' => [
                    'category' => [
                        'index' => ['0000089X' => 0, '0000089Y' => 1],
                    ],
                ],
                'Tid' => ['category' => ['index' => ['2022' => 0]]],
            ],
        ];

        Http::fake([
            'api.scb.se/*/AM/AM0210/*' => Http::response($response, 200),
        ]);

        $service = new ScbApiService;
        $result = $service->fetchHistorical('employment_rate', 2022);

        // employed / total * 100
        $this->assertEqualsWithDelta(80.0, $result['0180A0010'], 0.1);
        $this->assertEqualsWithDelta(66.67, $result['0180A0020'], 0.1);
    }

    // --- Command integration tests ---

    public function test_command_ingests_historical_data_with_crosswalk(): void
    {
        $indicator = Indicator::create([
            'slug' => 'median_income',
            'name' => 'Median Income',
            'source' => 'scb',
            'unit' => 'SEK',
            'direction' => 'positive',
            'weight' => 0.12,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'is_active' => true,
        ]);

        // Create crosswalk: 1:1 mapping
        DeSoCrosswalk::create([
            'old_code' => '0180A0010',
            'new_code' => '0180A0010',
            'overlap_fraction' => 1.0,
            'reverse_fraction' => 1.0,
            'mapping_type' => '1:1',
        ]);

        // Create a DeSO area so normalization doesn't fail
        $this->insertNewArea('0180A0010', [[18.0, 59.3], [18.1, 59.3], [18.1, 59.4], [18.0, 59.4], [18.0, 59.3]]);

        Http::fake([
            'api.scb.se/*' => Http::response($this->makeSimpleResponse('000008AB', [
                '0180A0010' => 300.0,
            ]), 200),
        ]);

        $this->artisan('ingest:scb-historical', [
            '--from' => 2019,
            '--to' => 2019,
            '--indicator' => 'median_income',
            '--skip-normalize' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('indicator_values', [
            'deso_code' => '0180A0010',
            'indicator_id' => $indicator->id,
            'year' => 2019,
            'raw_value' => 300000.0, // multiply_1000
        ]);
    }

    public function test_command_maps_split_areas_through_crosswalk(): void
    {
        $indicator = Indicator::create([
            'slug' => 'low_economic_standard_pct',
            'name' => 'Low Economic Standard',
            'source' => 'scb',
            'unit' => 'percent',
            'direction' => 'negative',
            'weight' => 0.08,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'is_active' => true,
        ]);

        // Split: one old area -> two new areas
        DeSoCrosswalk::create([
            'old_code' => '0180A0010',
            'new_code' => '0180A0011',
            'overlap_fraction' => 0.6,
            'reverse_fraction' => 1.0,
            'mapping_type' => 'split',
        ]);
        DeSoCrosswalk::create([
            'old_code' => '0180A0010',
            'new_code' => '0180A0012',
            'overlap_fraction' => 0.4,
            'reverse_fraction' => 1.0,
            'mapping_type' => 'split',
        ]);

        Http::fake([
            'api.scb.se/*' => Http::response($this->makeSimpleResponse('000008AC', [
                '0180A0010' => 12.5,
            ]), 200),
        ]);

        $this->artisan('ingest:scb-historical', [
            '--from' => 2020,
            '--to' => 2020,
            '--indicator' => 'low_economic_standard_pct',
            '--skip-normalize' => true,
        ])->assertExitCode(0);

        // Rate indicator: both children get parent's rate
        $this->assertDatabaseHas('indicator_values', [
            'deso_code' => '0180A0011',
            'indicator_id' => $indicator->id,
            'year' => 2020,
        ]);
        $this->assertDatabaseHas('indicator_values', [
            'deso_code' => '0180A0012',
            'indicator_id' => $indicator->id,
            'year' => 2020,
        ]);

        $val1 = IndicatorValue::where('deso_code', '0180A0011')->where('year', 2020)->first();
        $val2 = IndicatorValue::where('deso_code', '0180A0012')->where('year', 2020)->first();

        // For rate indicators, both children should get the same rate
        $this->assertEqualsWithDelta(12.5, $val1->raw_value, 0.01);
        $this->assertEqualsWithDelta(12.5, $val2->raw_value, 0.01);
    }

    public function test_command_skips_unavailable_years(): void
    {
        Indicator::create([
            'slug' => 'education_post_secondary_pct',
            'name' => 'Post-Secondary Education',
            'source' => 'scb',
            'unit' => 'percent',
            'direction' => 'positive',
            'weight' => 0.07,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'is_active' => true,
        ]);

        Http::fake();

        // Education starts at 2015, so years before that should be skipped
        $this->artisan('ingest:scb-historical', [
            '--from' => 2010,
            '--to' => 2014,
            '--indicator' => 'education_post_secondary_pct',
            '--skip-normalize' => true,
        ])->assertExitCode(0);

        // No API calls should have been made
        Http::assertNothingSent();
        $this->assertDatabaseCount('indicator_values', 0);
    }

    public function test_command_processes_population_as_count(): void
    {
        $indicator = Indicator::create([
            'slug' => 'population',
            'name' => 'Population',
            'source' => 'scb',
            'unit' => 'number',
            'direction' => 'neutral',
            'weight' => 0.00,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'is_active' => true,
        ]);

        // Split mapping for count indicator
        DeSoCrosswalk::create([
            'old_code' => '0180A0010',
            'new_code' => '0180A0011',
            'overlap_fraction' => 0.6,
            'reverse_fraction' => 1.0,
            'mapping_type' => 'split',
        ]);
        DeSoCrosswalk::create([
            'old_code' => '0180A0010',
            'new_code' => '0180A0012',
            'overlap_fraction' => 0.4,
            'reverse_fraction' => 1.0,
            'mapping_type' => 'split',
        ]);

        Http::fake([
            'api.scb.se/*' => Http::response($this->makeSimpleResponse('000007Y7', [
                '0180A0010' => 5000,
            ]), 200),
        ]);

        $this->artisan('ingest:scb-historical', [
            '--from' => 2019,
            '--to' => 2019,
            '--indicator' => 'population',
            '--skip-normalize' => true,
        ])->assertExitCode(0);

        // Count: proportional by area
        $val1 = IndicatorValue::where('deso_code', '0180A0011')->where('year', 2019)->first();
        $val2 = IndicatorValue::where('deso_code', '0180A0012')->where('year', 2019)->first();

        $this->assertEqualsWithDelta(3000, $val1->raw_value, 1);  // 5000 * 0.6
        $this->assertEqualsWithDelta(2000, $val2->raw_value, 1);  // 5000 * 0.4
    }

    public function test_command_from_greater_than_to_fails(): void
    {
        $this->artisan('ingest:scb-historical', [
            '--from' => 2023,
            '--to' => 2019,
        ])->assertExitCode(1);
    }

    // --- Helper methods ---

    /**
     * Build a simple JSON-stat2 response for a single-value indicator.
     *
     * @param  array<string, float>  $desoValues  [deso_code => value]
     */
    private function makeSimpleResponse(string $contentsCode, array $desoValues): array
    {
        $regionIndex = [];
        $values = [];
        $pos = 0;

        foreach ($desoValues as $code => $value) {
            $regionIndex[$code] = $pos++;
            $values[] = $value;
        }

        return [
            'id' => ['Region', 'ContentsCode', 'Tid'],
            'size' => [count($desoValues), 1, 1],
            'value' => $values,
            'dimension' => [
                'Region' => ['category' => ['index' => $regionIndex]],
                'ContentsCode' => ['category' => ['index' => [$contentsCode => 0]]],
                'Tid' => ['category' => ['index' => ['2019' => 0]]],
            ],
        ];
    }

    /**
     * Build an education JSON-stat2 response with 5 education levels.
     *
     * @param  string[]  $desoCodes
     */
    private function makeEducationResponse(array $desoCodes): array
    {
        $regionIndex = [];
        $values = [];

        foreach ($desoCodes as $i => $code) {
            $regionIndex[$code] = $i;
            // 21=50, 3+4=200, 5=80, 6=120, US=10
            array_push($values, 50, 200, 80, 120, 10);
        }

        return [
            'id' => ['Region', 'UtbildningsNiva', 'ContentsCode', 'Tid'],
            'size' => [count($desoCodes), 5, 1, 1],
            'value' => $values,
            'dimension' => [
                'Region' => ['category' => ['index' => $regionIndex]],
                'UtbildningsNiva' => [
                    'category' => [
                        'index' => ['21' => 0, '3+4' => 1, '5' => 2, '6' => 3, 'US' => 4],
                    ],
                ],
                'ContentsCode' => ['category' => ['index' => ['000005MO' => 0]]],
                'Tid' => ['category' => ['index' => ['2022' => 0]]],
            ],
        ];
    }

    private function insertNewArea(string $code, array $coords): void
    {
        $geojson = json_encode(['type' => 'Polygon', 'coordinates' => [$coords]]);

        DB::statement('
            INSERT INTO deso_areas (deso_code, kommun_code, lan_code, geom, created_at, updated_at)
            VALUES (:code, :kommun, :lan, ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326)), NOW(), NOW())
        ', [
            'code' => $code,
            'kommun' => substr($code, 0, 4),
            'lan' => substr($code, 0, 2),
            'geojson' => $geojson,
        ]);
    }
}
