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

        // Remove static file so tests exercise the DB fallback
        $staticPath = public_path('data/deso.geojson');
        if (file_exists($staticPath)) {
            rename($staticPath, $staticPath.'.bak');
        }
    }

    protected function tearDown(): void
    {
        // Restore static file if it was moved
        $staticPath = public_path('data/deso.geojson');
        if (file_exists($staticPath.'.bak')) {
            rename($staticPath.'.bak', $staticPath);
        }

        parent::tearDown();
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

    public function test_geojson_endpoint_serves_static_file_when_available(): void
    {
        $staticPath = public_path('data/deso.geojson');
        $dir = dirname($staticPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $testGeojson = json_encode([
            'type' => 'FeatureCollection',
            'features' => [],
        ]);
        file_put_contents($staticPath, $testGeojson);

        try {
            $response = $this->get(route('deso.geojson'));

            $response->assertOk();
            $response->assertHeader('Content-Type', 'application/json');
            $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response->baseResponse);
        } finally {
            // Remove the test file; tearDown will restore the real .bak if it exists
            unlink($staticPath);
        }
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
