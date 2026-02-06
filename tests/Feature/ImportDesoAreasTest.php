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

        // Back up real files so import tests don't destroy them
        $staticPath = public_path('data/deso.geojson');
        if (file_exists($staticPath)) {
            rename($staticPath, $staticPath.'.bak');
        }

        $cachePath = storage_path('app/geodata/deso_2025.geojson');
        if (file_exists($cachePath)) {
            rename($cachePath, $cachePath.'.bak');
        }
    }

    protected function tearDown(): void
    {
        // Clean up test artifacts and restore real files
        $staticPath = public_path('data/deso.geojson');
        if (file_exists($staticPath) && ! file_exists($staticPath.'.bak')) {
            unlink($staticPath);
        } elseif (file_exists($staticPath.'.bak')) {
            if (file_exists($staticPath)) {
                unlink($staticPath);
            }
            rename($staticPath.'.bak', $staticPath);
        }

        $cachePath = storage_path('app/geodata/deso_2025.geojson');
        if (file_exists($cachePath) && ! file_exists($cachePath.'.bak')) {
            unlink($cachePath);
        } elseif (file_exists($cachePath.'.bak')) {
            if (file_exists($cachePath)) {
                unlink($cachePath);
            }
            rename($cachePath.'.bak', $cachePath);
        }

        parent::tearDown();
    }

    public function test_import_command_succeeds_with_cached_geojson(): void
    {
        $this->writeCacheFile($this->makeSampleGeojson(3));

        $this->artisan('import:deso-areas', ['--fresh' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('deso_areas', 3);
    }

    public function test_import_command_with_fresh_flag_truncates_table(): void
    {
        // Insert a record first
        DB::table('deso_areas')->insert([
            'deso_code' => '9999X0001',
            'kommun_code' => '9999',
            'lan_code' => '99',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseCount('deso_areas', 1);

        $this->writeCacheFile($this->makeSampleGeojson(2));

        $this->artisan('import:deso-areas', ['--fresh' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('deso_areas', 2);
        $this->assertDatabaseMissing('deso_areas', ['deso_code' => '9999X0001']);
    }

    public function test_import_command_fails_without_cached_file_and_cache_only_flag(): void
    {
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

        $this->writeCacheFile($geojson);

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
    }

    public function test_import_command_generates_static_geojson_file(): void
    {
        $this->writeCacheFile($this->makeSampleGeojson(2));

        $this->artisan('import:deso-areas', ['--fresh' => true])
            ->assertExitCode(0);

        $staticPath = public_path('data/deso.geojson');
        $this->assertFileExists($staticPath);

        $content = json_decode(file_get_contents($staticPath), true);
        $this->assertEquals('FeatureCollection', $content['type']);
        $this->assertCount(2, $content['features']);
        $this->assertEquals('Feature', $content['features'][0]['type']);
        $this->assertArrayHasKey('geometry', $content['features'][0]);
        $this->assertArrayHasKey('properties', $content['features'][0]);
        $this->assertEquals('0180A0001', $content['features'][0]['properties']['deso_code']);
    }

    /**
     * @param  array<string, mixed>  $geojson
     */
    private function writeCacheFile(array $geojson): void
    {
        $cachePath = storage_path('app/geodata/deso_2025.geojson');

        if (! is_dir(dirname($cachePath))) {
            mkdir(dirname($cachePath), 0755, true);
        }

        file_put_contents($cachePath, json_encode($geojson));
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
