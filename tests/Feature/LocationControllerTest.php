<?php

namespace Tests\Feature;

use App\Models\CompositeScore;
use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    private function createDesoWithGeom(string $desoCode, string $kommunName, string $kommunCode = '0180'): void
    {
        DB::table('deso_areas')->insert([
            'deso_code' => $desoCode,
            'kommun_code' => $kommunCode,
            'kommun_name' => $kommunName,
            'lan_code' => substr($kommunCode, 0, 2),
            'urbanity_tier' => 'urban',
            'area_km2' => 0.5,
            'geom' => DB::raw("ST_SetSRID(ST_GeomFromText('POLYGON((18.05 59.33, 18.07 59.33, 18.07 59.34, 18.05 59.34, 18.05 59.33))'), 4326)"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_returns_location_data_for_valid_coordinates(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 72.5,
            'trend_1y' => 3.2,
            'factor_scores' => ['median_income' => 0.85],
            'top_positive' => ['median_income'],
            'top_negative' => ['crime_total_rate'],
            'computed_at' => now(),
        ]);

        $indicator = Indicator::create([
            'slug' => 'median_income',
            'name' => 'Medianinkomst',
            'unit' => 'SEK',
            'direction' => 'positive',
            'weight' => 0.09,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'source' => 'scb',
            'is_active' => true,
            'display_order' => 1,
        ]);

        IndicatorValue::create([
            'deso_code' => '0180C1090',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 287000,
            'normalized_value' => 0.78,
        ]);

        $response = $this->getJson('/api/location/59.335,18.06');

        $response->assertOk()
            ->assertJsonStructure([
                'location' => ['lat', 'lng', 'deso_code', 'kommun', 'lan_code', 'area_km2', 'urbanity_tier'],
                'score' => ['value', 'trend_1y', 'label', 'top_positive', 'top_negative', 'factor_scores'],
                'indicators',
                'schools',
            ])
            ->assertJsonFragment([
                'kommun' => 'Stockholm',
                'deso_code' => '0180C1090',
            ])
            ->assertJsonPath('score.value', 72.5)
            ->assertJsonPath('score.label', 'Stabilt / Positivt');
    }

    public function test_returns_404_for_coordinates_outside_sweden(): void
    {
        $response = $this->getJson('/api/location/48.8566,2.3522');

        $response->assertNotFound()
            ->assertJson(['error' => 'Location outside Sweden']);
    }

    public function test_returns_nearby_schools(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 65.0,
            'computed_at' => now(),
        ]);

        School::factory()->create([
            'name' => 'Vasaskolan',
            'type_of_schooling' => 'Grundskola',
            'operator_type' => 'KOMMUN',
            'status' => 'AKTIV',
            'lat' => 59.335,
            'lng' => 18.061,
            'deso_code' => '0180C1090',
        ]);

        DB::statement('
            UPDATE schools SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326)
            WHERE lat IS NOT NULL AND lng IS NOT NULL AND geom IS NULL
        ');

        $response = $this->getJson('/api/location/59.335,18.06');

        $response->assertOk();
        $schools = $response->json('schools');
        $this->assertCount(1, $schools);
        $this->assertEquals('Vasaskolan', $schools[0]['name']);
        $this->assertArrayHasKey('distance_m', $schools[0]);
    }

    public function test_returns_indicators_for_location(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 55.0,
            'computed_at' => now(),
        ]);

        $indicator = Indicator::create([
            'slug' => 'employment_rate',
            'name' => 'Sysselsättningsgrad',
            'unit' => '%',
            'direction' => 'positive',
            'weight' => 0.08,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'source' => 'scb',
            'is_active' => true,
            'display_order' => 2,
        ]);

        IndicatorValue::create([
            'deso_code' => '0180C1090',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 72.3,
            'normalized_value' => 0.61,
        ]);

        $response = $this->getJson('/api/location/59.335,18.06');

        $response->assertOk();
        $indicators = $response->json('indicators');
        $this->assertCount(1, $indicators);
        $this->assertEquals('employment_rate', $indicators[0]['slug']);
        $this->assertEquals(72.3, $indicators[0]['raw_value']);
    }

    public function test_score_labels_are_correct(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        // Test all score labels
        $cases = [
            [15.0, 'Hög risk'],
            [25.0, 'Förhöjd risk'],
            [45.0, 'Blandat'],
            [65.0, 'Stabilt / Positivt'],
            [85.0, 'Starkt tillväxtområde'],
        ];

        foreach ($cases as [$score, $expectedLabel]) {
            CompositeScore::where('deso_code', '0180C1090')->delete();
            CompositeScore::create([
                'deso_code' => '0180C1090',
                'year' => 2024,
                'score' => $score,
                'computed_at' => now(),
            ]);

            $response = $this->getJson('/api/location/59.335,18.06');
            $response->assertJsonPath('score.label', $expectedLabel);
        }
    }

    public function test_returns_null_score_when_no_composite_score_exists(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        $response = $this->getJson('/api/location/59.335,18.06');

        $response->assertOk()
            ->assertJsonPath('score', null)
            ->assertJsonPath('location.deso_code', '0180C1090');
    }

    public function test_excludes_inactive_indicators(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 50.0,
            'computed_at' => now(),
        ]);

        $activeIndicator = Indicator::create([
            'slug' => 'median_income',
            'name' => 'Medianinkomst',
            'unit' => 'SEK',
            'direction' => 'positive',
            'weight' => 0.09,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'source' => 'scb',
            'is_active' => true,
            'display_order' => 1,
        ]);

        $inactiveIndicator = Indicator::create([
            'slug' => 'old_metric',
            'name' => 'Old Metric',
            'unit' => '%',
            'direction' => 'positive',
            'weight' => 0.0,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'source' => 'scb',
            'is_active' => false,
            'display_order' => 99,
        ]);

        IndicatorValue::create([
            'deso_code' => '0180C1090',
            'indicator_id' => $activeIndicator->id,
            'year' => 2024,
            'raw_value' => 287000,
            'normalized_value' => 0.78,
        ]);

        IndicatorValue::create([
            'deso_code' => '0180C1090',
            'indicator_id' => $inactiveIndicator->id,
            'year' => 2024,
            'raw_value' => 50.0,
            'normalized_value' => 0.50,
        ]);

        $response = $this->getJson('/api/location/59.335,18.06');

        $response->assertOk();
        $indicators = $response->json('indicators');
        $this->assertCount(1, $indicators);
        $this->assertEquals('median_income', $indicators[0]['slug']);
    }

    public function test_tile_route_returns_transparent_png_for_missing_tile(): void
    {
        $response = $this->get('/tiles/2024/5/0/0.png');

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_explore_route_renders_map_page(): void
    {
        $response = $this->get('/explore/59.3340,18.0650');

        $response->assertOk();
    }
}
