<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    private function insertDesoWithGeometry(string $desoCode, string $kommunCode, string $kommunName, string $lanCode, string $lanName, float $lng = 18.05, float $lat = 59.35): void
    {
        $offset = 0.01;
        $wkt = sprintf(
            'POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))',
            $lng - $offset, $lat - $offset,
            $lng + $offset, $lat - $offset,
            $lng + $offset, $lat + $offset,
            $lng - $offset, $lat + $offset,
            $lng - $offset, $lat - $offset,
        );

        DB::statement('
            INSERT INTO deso_areas (deso_code, deso_name, kommun_code, kommun_name, lan_code, lan_name, geom, area_km2, created_at, updated_at)
            VALUES (
                :deso_code, :deso_name, :kommun_code, :kommun_name, :lan_code, :lan_name,
                ST_Multi(ST_SetSRID(ST_GeomFromText(:wkt), 4326)),
                1.5, NOW(), NOW()
            )
        ', [
            'deso_code' => $desoCode,
            'deso_name' => $desoCode.' Name',
            'kommun_code' => $kommunCode,
            'kommun_name' => $kommunName,
            'lan_code' => $lanCode,
            'lan_name' => $lanName,
            'wkt' => $wkt,
        ]);
    }

    private function seedTwoLocations(): void
    {
        // Location A: Stockholm area
        $this->insertDesoWithGeometry('0180C1020', '0180', 'Stockholm', '01', 'Stockholms län', 18.07, 59.33);

        // Location B: Different area
        $this->insertDesoWithGeometry('0180C2030', '0180', 'Stockholm', '01', 'Stockholms län', 18.03, 59.34);

        // Create an indicator with values
        $indicatorId = DB::table('indicators')->insertGetId([
            'slug' => 'median_income',
            'name' => 'Median Income',
            'source' => 'SCB',
            'is_active' => true,
            'direction' => 'positive',
            'weight' => 0.10,
            'normalization' => 'rank_percentile',
            'unit' => 'SEK',
            'normalization_scope' => 'national',
            'display_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('indicator_values')->insert([
            [
                'deso_code' => '0180C1020',
                'indicator_id' => $indicatorId,
                'year' => 2024,
                'raw_value' => 287000,
                'normalized_value' => 0.78,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'deso_code' => '0180C2030',
                'indicator_id' => $indicatorId,
                'year' => 2024,
                'raw_value' => 245000,
                'normalized_value' => 0.64,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Composite scores
        DB::table('composite_scores')->insert([
            [
                'deso_code' => '0180C1020',
                'year' => 2024,
                'score' => 72.4,
                'computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'deso_code' => '0180C2030',
                'year' => 2024,
                'score' => 58.1,
                'computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_compare_endpoint_returns_valid_response(): void
    {
        $this->seedTwoLocations();

        $response = $this->postJson('/api/compare', [
            'point_a' => ['lat' => 59.33, 'lng' => 18.07],
            'point_b' => ['lat' => 59.34, 'lng' => 18.03],
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'location_a' => ['lat', 'lng', 'deso_code', 'deso_name', 'kommun_name', 'label', 'composite_score', 'indicators'],
                'location_b' => ['lat', 'lng', 'deso_code', 'deso_name', 'kommun_name', 'label', 'composite_score', 'indicators'],
                'distance_km',
                'comparison' => ['score_difference', 'a_stronger', 'b_stronger', 'similar'],
            ]);
    }

    public function test_compare_returns_correct_deso_codes(): void
    {
        $this->seedTwoLocations();

        $response = $this->postJson('/api/compare', [
            'point_a' => ['lat' => 59.33, 'lng' => 18.07],
            'point_b' => ['lat' => 59.34, 'lng' => 18.03],
        ]);

        $response->assertOk();
        $data = $response->json();

        $this->assertEquals('0180C1020', $data['location_a']['deso_code']);
        $this->assertEquals('0180C2030', $data['location_b']['deso_code']);
    }

    public function test_compare_returns_distance(): void
    {
        $this->seedTwoLocations();

        $response = $this->postJson('/api/compare', [
            'point_a' => ['lat' => 59.33, 'lng' => 18.07],
            'point_b' => ['lat' => 59.34, 'lng' => 18.03],
        ]);

        $data = $response->json();
        $this->assertGreaterThan(0, $data['distance_km']);
        $this->assertLessThan(10, $data['distance_km']);
    }

    public function test_compare_returns_composite_scores(): void
    {
        $this->seedTwoLocations();

        $response = $this->postJson('/api/compare', [
            'point_a' => ['lat' => 59.33, 'lng' => 18.07],
            'point_b' => ['lat' => 59.34, 'lng' => 18.03],
        ]);

        $data = $response->json();
        $this->assertEquals(72.4, $data['location_a']['composite_score']);
        $this->assertEquals(58.1, $data['location_b']['composite_score']);
    }

    public function test_compare_returns_indicator_data(): void
    {
        $this->seedTwoLocations();

        $response = $this->postJson('/api/compare', [
            'point_a' => ['lat' => 59.33, 'lng' => 18.07],
            'point_b' => ['lat' => 59.34, 'lng' => 18.03],
        ]);

        $data = $response->json();
        $this->assertArrayHasKey('median_income', $data['location_a']['indicators']);
        $this->assertEquals(78, $data['location_a']['indicators']['median_income']['percentile']);
    }

    public function test_compare_builds_correct_comparison(): void
    {
        $this->seedTwoLocations();

        $response = $this->postJson('/api/compare', [
            'point_a' => ['lat' => 59.33, 'lng' => 18.07],
            'point_b' => ['lat' => 59.34, 'lng' => 18.03],
        ]);

        $data = $response->json();
        $this->assertNotNull($data['comparison']['score_difference']);
        $this->assertGreaterThan(0, $data['comparison']['score_difference']);
        $this->assertContains('median_income', $data['comparison']['a_stronger']);
    }

    public function test_compare_validates_request(): void
    {
        $response = $this->postJson('/api/compare', []);
        $response->assertUnprocessable();

        $response = $this->postJson('/api/compare', [
            'point_a' => ['lat' => 90, 'lng' => 180],
            'point_b' => ['lat' => 59.33, 'lng' => 18.07],
        ]);
        $response->assertUnprocessable();
    }

    public function test_compare_handles_unknown_location(): void
    {
        $response = $this->postJson('/api/compare', [
            'point_a' => ['lat' => 67.0, 'lng' => 20.0],
            'point_b' => ['lat' => 67.1, 'lng' => 20.1],
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertNull($data['location_a']['deso_code']);
        $this->assertNull($data['location_b']['deso_code']);
    }

    public function test_compare_handles_same_location(): void
    {
        $this->seedTwoLocations();

        $response = $this->postJson('/api/compare', [
            'point_a' => ['lat' => 59.33, 'lng' => 18.07],
            'point_b' => ['lat' => 59.33, 'lng' => 18.07],
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertLessThan(0.01, $data['distance_km']);
        $this->assertEquals($data['location_a']['deso_code'], $data['location_b']['deso_code']);
    }
}
