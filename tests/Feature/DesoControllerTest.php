<?php

namespace Tests\Feature;

use App\Models\DesoArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DesoControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function test_geojson_endpoint_returns_feature_collection(): void
    {
        $response = $this->getJson(route('deso.geojson'));

        $response->assertOk();
        $response->assertJsonStructure([
            'type',
            'features',
        ]);
        $response->assertJson(['type' => 'FeatureCollection']);
    }

    public function test_geojson_endpoint_returns_deso_features(): void
    {
        $this->insertDesoWithGeometry('0114A0010', '0114', '01', 'Upplands Väsby');

        $response = $this->getJson(route('deso.geojson'));

        $response->assertOk();
        $response->assertJsonCount(1, 'features');
        $response->assertJsonPath('features.0.properties.deso_code', '0114A0010');
        $response->assertJsonPath('features.0.properties.kommun_code', '0114');
        $response->assertJsonPath('features.0.properties.kommun_name', 'Upplands Väsby');
        $response->assertJsonPath('features.0.type', 'Feature');
    }

    public function test_geojson_endpoint_accepts_tolerance_parameter(): void
    {
        $this->insertDesoWithGeometry('0114A0010', '0114', '01', 'Upplands Väsby');

        $response = $this->getJson(route('deso.geojson', ['tolerance' => 0.001]));

        $response->assertOk();
        $response->assertJsonCount(1, 'features');
    }

    public function test_geojson_endpoint_has_cache_header(): void
    {
        $response = $this->getJson(route('deso.geojson'));

        $response->assertHeader('Cache-Control');
    }

    public function test_geojson_endpoint_excludes_null_geometries(): void
    {
        DesoArea::factory()->create(['deso_code' => '0114A0010', 'kommun_code' => '0114', 'lan_code' => '01']);

        $response = $this->getJson(route('deso.geojson'));

        $response->assertOk();
        $response->assertJsonCount(0, 'features');
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
