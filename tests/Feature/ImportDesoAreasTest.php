<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportDesoAreasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function test_import_command_succeeds_with_cached_geojson(): void
    {
        $geojson = $this->makeSampleGeojson(3);
        $cachePath = storage_path('app/geodata/deso_2025.geojson');

        if (! is_dir(dirname($cachePath))) {
            mkdir(dirname($cachePath), 0755, true);
        }

        file_put_contents($cachePath, json_encode($geojson));

        try {
            $this->artisan('import:deso-areas', ['--fresh' => true])
                ->assertExitCode(0);

            $this->assertDatabaseCount('deso_areas', 3);
        } finally {
            unlink($cachePath);
        }
    }

    public function test_import_command_with_fresh_flag_truncates_table(): void
    {
        $geojson = $this->makeSampleGeojson(2);
        $cachePath = storage_path('app/geodata/deso_2025.geojson');

        if (! is_dir(dirname($cachePath))) {
            mkdir(dirname($cachePath), 0755, true);
        }

        // Insert a record first
        DB::table('deso_areas')->insert([
            'deso_code' => '9999X0001',
            'kommun_code' => '9999',
            'lan_code' => '99',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseCount('deso_areas', 1);

        file_put_contents($cachePath, json_encode($geojson));

        try {
            $this->artisan('import:deso-areas', ['--fresh' => true])
                ->assertExitCode(0);

            $this->assertDatabaseCount('deso_areas', 2);
            $this->assertDatabaseMissing('deso_areas', ['deso_code' => '9999X0001']);
        } finally {
            unlink($cachePath);
        }
    }

    public function test_import_command_fails_without_cached_file_and_cache_only_flag(): void
    {
        $cachePath = storage_path('app/geodata/deso_2025.geojson');
        if (file_exists($cachePath)) {
            unlink($cachePath);
        }

        $this->artisan('import:deso-areas', ['--cache-only' => true])
            ->assertExitCode(1);
    }

    public function test_import_command_stores_correct_properties(): void
    {
        $geojson = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[18.0, 59.3], [18.1, 59.3], [18.1, 59.4], [18.0, 59.4], [18.0, 59.3]]],
                    ],
                    'properties' => [
                        'desokod' => '0180A0010',
                        'kommunkod' => '0180',
                        'kommunnamn' => 'Stockholm',
                        'lanskod' => '01',
                    ],
                ],
            ],
        ];

        $cachePath = storage_path('app/geodata/deso_2025.geojson');
        if (! is_dir(dirname($cachePath))) {
            mkdir(dirname($cachePath), 0755, true);
        }
        file_put_contents($cachePath, json_encode($geojson));

        try {
            $this->artisan('import:deso-areas', ['--fresh' => true])
                ->assertExitCode(0);

            $this->assertDatabaseHas('deso_areas', [
                'deso_code' => '0180A0010',
                'kommun_code' => '0180',
                'kommun_name' => 'Stockholm',
                'lan_code' => '01',
            ]);

            $area = DB::selectOne("SELECT area_km2, ST_AsText(geom) as geom_text FROM deso_areas WHERE deso_code = '0180A0010'");
            $this->assertNotNull($area->area_km2);
            $this->assertGreaterThan(0, $area->area_km2);
            $this->assertNotNull($area->geom_text);
        } finally {
            unlink($cachePath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function makeSampleGeojson(int $count): array
    {
        $features = [];
        for ($i = 1; $i <= $count; $i++) {
            $lng = 18.0 + ($i * 0.01);
            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[$lng, 59.3], [$lng + 0.01, 59.3], [$lng + 0.01, 59.31], [$lng, 59.31], [$lng, 59.3]]],
                ],
                'properties' => [
                    'desokod' => sprintf('0180A%04d', $i),
                    'kommunkod' => '0180',
                    'kommunnamn' => 'Stockholm',
                    'lanskod' => '01',
                ],
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }
}
