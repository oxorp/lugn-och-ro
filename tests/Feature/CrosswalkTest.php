<?php

namespace Tests\Feature;

use App\Models\DeSoCrosswalk;
use App\Services\CrosswalkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrosswalkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        // Back up real cached files so tests don't destroy them
        $cachePath = storage_path('app/geodata/deso_2018.geojson');
        if (file_exists($cachePath)) {
            rename($cachePath, $cachePath.'.bak');
        }
    }

    protected function tearDown(): void
    {
        $cachePath = storage_path('app/geodata/deso_2018.geojson');
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

    // --- Import command tests ---

    public function test_import_2018_command_succeeds_with_cached_geojson(): void
    {
        $this->writeCacheFile($this->makeSampleGeojson2018(3));

        $this->artisan('import:deso-2018-boundaries', ['--fresh' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('deso_areas_2018', 3);
    }

    public function test_import_2018_command_fails_without_cached_file_and_cache_only(): void
    {
        $this->artisan('import:deso-2018-boundaries', ['--cache-only' => true])
            ->assertExitCode(1);
    }

    public function test_import_2018_stores_correct_properties(): void
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
                    ],
                ],
            ],
        ];

        $this->writeCacheFile($geojson);

        $this->artisan('import:deso-2018-boundaries', ['--fresh' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('deso_areas_2018', [
            'deso_code' => '0180A0010',
            'kommun_code' => '0180',
            'kommun_name' => 'Stockholm',
        ]);

        $area = DB::selectOne("SELECT ST_AsText(geom) as geom_text FROM deso_areas_2018 WHERE deso_code = '0180A0010'");
        $this->assertNotNull($area->geom_text);
    }

    public function test_import_2018_fresh_flag_truncates_table(): void
    {
        DB::table('deso_areas_2018')->insert([
            'deso_code' => '9999X0001',
            'kommun_code' => '9999',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseCount('deso_areas_2018', 1);

        $this->writeCacheFile($this->makeSampleGeojson2018(2));

        $this->artisan('import:deso-2018-boundaries', ['--fresh' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('deso_areas_2018', 2);
        $this->assertDatabaseMissing('deso_areas_2018', ['deso_code' => '9999X0001']);
    }

    // --- Crosswalk computation tests ---

    public function test_build_crosswalk_fails_without_2018_areas(): void
    {
        $this->artisan('build:deso-crosswalk')
            ->assertExitCode(1);
    }

    public function test_build_crosswalk_creates_one_to_one_mapping(): void
    {
        // Create identical geometry in both tables → should be 1:1
        $this->insertOldArea('0180A0010', [[18.0, 59.3], [18.1, 59.3], [18.1, 59.4], [18.0, 59.4], [18.0, 59.3]]);
        $this->insertNewArea('0180A0010', [[18.0, 59.3], [18.1, 59.3], [18.1, 59.4], [18.0, 59.4], [18.0, 59.3]]);

        $this->artisan('build:deso-crosswalk', ['--fresh' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('deso_crosswalk', [
            'old_code' => '0180A0010',
            'new_code' => '0180A0010',
            'mapping_type' => '1:1',
        ]);

        $mapping = DeSoCrosswalk::where('old_code', '0180A0010')->first();
        $this->assertGreaterThan(0.95, $mapping->overlap_fraction);
    }

    public function test_build_crosswalk_detects_split(): void
    {
        // One old area covering a wide region
        $this->insertOldArea('0180A0010', [[18.0, 59.3], [18.2, 59.3], [18.2, 59.4], [18.0, 59.4], [18.0, 59.3]]);

        // Two new areas splitting it left/right
        $this->insertNewArea('0180A0011', [[18.0, 59.3], [18.1, 59.3], [18.1, 59.4], [18.0, 59.4], [18.0, 59.3]]);
        $this->insertNewArea('0180A0012', [[18.1, 59.3], [18.2, 59.3], [18.2, 59.4], [18.1, 59.4], [18.1, 59.3]]);

        $this->artisan('build:deso-crosswalk', ['--fresh' => true])
            ->assertExitCode(0);

        $mappings = DeSoCrosswalk::where('old_code', '0180A0010')->get();
        $this->assertCount(2, $mappings);

        // Both should be 'split' type (old area < 95% in each new)
        foreach ($mappings as $mapping) {
            $this->assertEquals('split', $mapping->mapping_type);
        }

        // Overlap fractions should sum to ~1.0
        $totalOverlap = $mappings->sum('overlap_fraction');
        $this->assertEqualsWithDelta(1.0, $totalOverlap, 0.05);
    }

    public function test_build_crosswalk_detects_merge(): void
    {
        // Two old areas
        $this->insertOldArea('0180A0010', [[18.0, 59.3], [18.1, 59.3], [18.1, 59.4], [18.0, 59.4], [18.0, 59.3]]);
        $this->insertOldArea('0180A0020', [[18.1, 59.3], [18.2, 59.3], [18.2, 59.4], [18.1, 59.4], [18.1, 59.3]]);

        // One new area covering both
        $this->insertNewArea('0180A0030', [[18.0, 59.3], [18.2, 59.3], [18.2, 59.4], [18.0, 59.4], [18.0, 59.3]]);

        $this->artisan('build:deso-crosswalk', ['--fresh' => true])
            ->assertExitCode(0);

        // Each old code should map to the new code
        $this->assertDatabaseHas('deso_crosswalk', ['old_code' => '0180A0010', 'new_code' => '0180A0030']);
        $this->assertDatabaseHas('deso_crosswalk', ['old_code' => '0180A0020', 'new_code' => '0180A0030']);

        // Both should have high overlap (each old area is fully within the new one)
        $mapping1 = DeSoCrosswalk::where('old_code', '0180A0010')->first();
        $mapping2 = DeSoCrosswalk::where('old_code', '0180A0020')->first();
        $this->assertGreaterThan(0.95, $mapping1->overlap_fraction);
        $this->assertGreaterThan(0.95, $mapping2->overlap_fraction);
    }

    // --- CrosswalkService tests ---

    public function test_service_maps_rate_indicator_for_one_to_one(): void
    {
        DeSoCrosswalk::create([
            'old_code' => '0180A0010',
            'new_code' => '0180A0010',
            'overlap_fraction' => 1.0,
            'reverse_fraction' => 1.0,
            'mapping_type' => '1:1',
        ]);

        $service = new CrosswalkService;
        $result = $service->mapOldToNew('0180A0010', 350000.0, 'SEK');

        $this->assertEquals(['0180A0010' => 350000.0], $result);
    }

    public function test_service_maps_rate_indicator_for_split(): void
    {
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

        $service = new CrosswalkService;

        // Rate: same value for all children
        $result = $service->mapOldToNew('0180A0010', 75.5, 'percent');
        $this->assertEquals(75.5, $result['0180A0011']);
        $this->assertEquals(75.5, $result['0180A0012']);
    }

    public function test_service_maps_count_indicator_for_split(): void
    {
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

        $service = new CrosswalkService;

        // Count: proportional by area
        $result = $service->mapOldToNew('0180A0010', 2000.0, 'count');
        $this->assertEqualsWithDelta(1200.0, $result['0180A0011'], 0.01);
        $this->assertEqualsWithDelta(800.0, $result['0180A0012'], 0.01);
    }

    public function test_service_returns_empty_for_unknown_code(): void
    {
        $service = new CrosswalkService;
        $result = $service->mapOldToNew('9999X9999', 100.0, 'percent');

        $this->assertEmpty($result);
    }

    public function test_service_bulk_maps_rate_indicators(): void
    {
        // 1:1 mapping
        DeSoCrosswalk::create([
            'old_code' => '0180A0010',
            'new_code' => '0180A0010',
            'overlap_fraction' => 1.0,
            'reverse_fraction' => 1.0,
            'mapping_type' => '1:1',
        ]);

        // Split mapping
        DeSoCrosswalk::create([
            'old_code' => '0180A0020',
            'new_code' => '0180A0021',
            'overlap_fraction' => 0.6,
            'reverse_fraction' => 1.0,
            'mapping_type' => 'split',
        ]);
        DeSoCrosswalk::create([
            'old_code' => '0180A0020',
            'new_code' => '0180A0022',
            'overlap_fraction' => 0.4,
            'reverse_fraction' => 1.0,
            'mapping_type' => 'split',
        ]);

        $service = new CrosswalkService;
        $result = $service->bulkMapOldToNew([
            '0180A0010' => 350000.0,
            '0180A0020' => 75.5,
        ], 'SEK');

        // 1:1 should preserve value exactly
        $this->assertEqualsWithDelta(350000.0, $result['0180A0010'], 0.01);

        // Split rate: both children get the parent's rate
        $this->assertEqualsWithDelta(75.5, $result['0180A0021'], 0.01);
        $this->assertEqualsWithDelta(75.5, $result['0180A0022'], 0.01);
    }

    public function test_service_bulk_maps_count_indicators(): void
    {
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

        $service = new CrosswalkService;
        $result = $service->bulkMapOldToNew([
            '0180A0010' => 2000.0,
        ], 'count');

        $this->assertEqualsWithDelta(1200.0, $result['0180A0011'], 0.01);
        $this->assertEqualsWithDelta(800.0, $result['0180A0012'], 0.01);
    }

    public function test_service_bulk_maps_merged_rate_indicators(): void
    {
        // Two old areas merge into one new area
        DeSoCrosswalk::create([
            'old_code' => '0180A0010',
            'new_code' => '0180A0030',
            'overlap_fraction' => 1.0,
            'reverse_fraction' => 0.5,
            'mapping_type' => 'merge',
        ]);
        DeSoCrosswalk::create([
            'old_code' => '0180A0020',
            'new_code' => '0180A0030',
            'overlap_fraction' => 1.0,
            'reverse_fraction' => 0.5,
            'mapping_type' => 'merge',
        ]);

        $service = new CrosswalkService;
        $result = $service->bulkMapOldToNew([
            '0180A0010' => 300000.0,
            '0180A0020' => 400000.0,
        ], 'SEK');

        // Area-weighted average: (300000 * 0.5 + 400000 * 0.5) / (0.5 + 0.5) = 350000
        $this->assertEqualsWithDelta(350000.0, $result['0180A0030'], 0.01);
    }

    public function test_service_bulk_maps_merged_count_indicators(): void
    {
        DeSoCrosswalk::create([
            'old_code' => '0180A0010',
            'new_code' => '0180A0030',
            'overlap_fraction' => 1.0,
            'reverse_fraction' => 0.5,
            'mapping_type' => 'merge',
        ]);
        DeSoCrosswalk::create([
            'old_code' => '0180A0020',
            'new_code' => '0180A0030',
            'overlap_fraction' => 1.0,
            'reverse_fraction' => 0.5,
            'mapping_type' => 'merge',
        ]);

        $service = new CrosswalkService;
        $result = $service->bulkMapOldToNew([
            '0180A0010' => 1000.0,
            '0180A0020' => 2000.0,
        ], 'count');

        // Counts sum: 1000 * 1.0 + 2000 * 1.0 = 3000
        $this->assertEqualsWithDelta(3000.0, $result['0180A0030'], 0.01);
    }

    public function test_service_skips_unmapped_codes_in_bulk(): void
    {
        DeSoCrosswalk::create([
            'old_code' => '0180A0010',
            'new_code' => '0180A0010',
            'overlap_fraction' => 1.0,
            'reverse_fraction' => 1.0,
            'mapping_type' => '1:1',
        ]);

        $service = new CrosswalkService;
        $result = $service->bulkMapOldToNew([
            '0180A0010' => 350000.0,
            '9999X9999' => 100000.0, // unmapped
        ], 'SEK');

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('0180A0010', $result);
        $this->assertArrayNotHasKey('9999X9999', $result);
    }

    // --- Helper methods ---

    private function insertOldArea(string $code, array $coords): void
    {
        $geojson = json_encode(['type' => 'Polygon', 'coordinates' => [$coords]]);

        DB::statement('
            INSERT INTO deso_areas_2018 (deso_code, kommun_code, geom, created_at, updated_at)
            VALUES (:code, :kommun, ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326)), NOW(), NOW())
        ', [
            'code' => $code,
            'kommun' => substr($code, 0, 4),
            'geojson' => $geojson,
        ]);
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

    private function writeCacheFile(array $geojson): void
    {
        $cachePath = storage_path('app/geodata/deso_2018.geojson');

        if (! is_dir(dirname($cachePath))) {
            mkdir(dirname($cachePath), 0755, true);
        }

        file_put_contents($cachePath, json_encode($geojson));
    }

    /**
     * @return array<string, mixed>
     */
    private function makeSampleGeojson2018(int $count): array
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
                ],
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }
}
