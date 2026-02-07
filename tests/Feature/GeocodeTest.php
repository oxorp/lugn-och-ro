<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GeocodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function test_resolve_deso_returns_matching_deso(): void
    {
        $this->insertDesoWithGeometry('0180C1060', '0180', '01', 'Stockholm', 'Norrmalm');

        // Point inside the polygon (18.05, 59.35)
        $response = $this->getJson('/api/geocode/resolve-deso?lat=59.35&lng=18.05');

        $response->assertOk();
        $response->assertJsonPath('deso.deso_code', '0180C1060');
        $response->assertJsonPath('deso.deso_name', 'Norrmalm');
        $response->assertJsonPath('deso.kommun_name', 'Stockholm');
        $response->assertJsonPath('deso.lan_name', null);
    }

    public function test_resolve_deso_returns_null_when_no_match(): void
    {
        $this->insertDesoWithGeometry('0180C1060', '0180', '01', 'Stockholm', 'Norrmalm');

        // Point far outside the polygon
        $response = $this->getJson('/api/geocode/resolve-deso?lat=65.0&lng=20.0');

        $response->assertOk();
        $response->assertJsonPath('deso', null);
    }

    public function test_resolve_deso_falls_back_to_nearest_within_500m(): void
    {
        // Create a polygon at 18.0-18.1, 59.3-59.4
        $this->insertDesoWithGeometry('0180C1060', '0180', '01', 'Stockholm', 'Norrmalm');

        // Point slightly outside the polygon boundary (just beyond 18.1)
        // This is within 500m of the polygon edge
        $response = $this->getJson('/api/geocode/resolve-deso?lat=59.35&lng=18.101');

        $response->assertOk();
        $response->assertJsonPath('deso.deso_code', '0180C1060');
    }

    public function test_resolve_deso_validates_lat_range(): void
    {
        $response = $this->getJson('/api/geocode/resolve-deso?lat=40.0&lng=18.0');

        $response->assertStatus(422);
    }

    public function test_resolve_deso_validates_lng_range(): void
    {
        $response = $this->getJson('/api/geocode/resolve-deso?lat=59.3&lng=5.0');

        $response->assertStatus(422);
    }

    public function test_resolve_deso_validates_required_params(): void
    {
        $response = $this->getJson('/api/geocode/resolve-deso');

        $response->assertStatus(422);
    }

    public function test_resolve_deso_returns_closest_when_multiple_nearby(): void
    {
        // Two DeSOs side by side
        $this->insertDesoWithGeometry('0180C1060', '0180', '01', 'Stockholm', 'Norrmalm');

        DB::statement("
            INSERT INTO deso_areas (deso_code, kommun_code, kommun_name, lan_code, deso_name, geom, area_km2, created_at, updated_at)
            VALUES (
                '0180C1070', '0180', 'Stockholm', '01', 'Vasastan',
                ST_Multi(ST_SetSRID(ST_GeomFromText('POLYGON((18.1 59.3, 18.2 59.3, 18.2 59.4, 18.1 59.4, 18.1 59.3))'), 4326)),
                1.5, NOW(), NOW()
            )
        ");

        // Point inside the second polygon
        $response = $this->getJson('/api/geocode/resolve-deso?lat=59.35&lng=18.15');

        $response->assertOk();
        $response->assertJsonPath('deso.deso_code', '0180C1070');
        $response->assertJsonPath('deso.deso_name', 'Vasastan');
    }

    private function insertDesoWithGeometry(
        string $desoCode,
        string $kommunCode,
        string $lanCode,
        string $kommunName,
        ?string $desoName = null,
    ): void {
        DB::statement("
            INSERT INTO deso_areas (deso_code, kommun_code, kommun_name, lan_code, deso_name, geom, area_km2, created_at, updated_at)
            VALUES (
                :deso_code, :kommun_code, :kommun_name, :lan_code, :deso_name,
                ST_Multi(ST_SetSRID(ST_GeomFromText('POLYGON((18.0 59.3, 18.1 59.3, 18.1 59.4, 18.0 59.4, 18.0 59.3))'), 4326)),
                1.5, NOW(), NOW()
            )
        ", [
            'deso_code' => $desoCode,
            'kommun_code' => $kommunCode,
            'kommun_name' => $kommunName,
            'lan_code' => $lanCode,
            'deso_name' => $desoName,
        ]);
    }
}
