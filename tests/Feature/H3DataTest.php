<?php

namespace Tests\Feature;

use App\Models\SmoothingConfig;
use App\Services\SpatialSmoothingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class H3DataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        DB::statement('CREATE EXTENSION IF NOT EXISTS h3');
        DB::statement('CREATE EXTENSION IF NOT EXISTS h3_postgis CASCADE');
    }

    private function insertDesoWithGeometry(string $desoCode, string $kommunCode, string $lanCode): void
    {
        DB::statement("
            INSERT INTO deso_areas (deso_code, kommun_code, kommun_name, lan_code, geom, area_km2, created_at, updated_at)
            VALUES (
                :deso_code, :kommun_code, 'TestKommun', :lan_code,
                ST_Multi(ST_SetSRID(ST_GeomFromText('POLYGON((18.0 59.3, 18.1 59.3, 18.1 59.4, 18.0 59.4, 18.0 59.3))'), 4326)),
                1.5, NOW(), NOW()
            )
        ", [
            'deso_code' => $desoCode,
            'kommun_code' => $kommunCode,
            'lan_code' => $lanCode,
        ]);
    }

    private function seedH3Data(): void
    {
        $this->insertDesoWithGeometry('0114A0010', '0114', '01');

        $this->artisan('build:deso-h3-mapping', ['--resolution' => 8])
            ->assertExitCode(0);

        DB::table('composite_scores')->insert([
            'deso_code' => '0114A0010',
            'year' => 2024,
            'score' => 65.50,
            'trend_1y' => 2.3,
            'factor_scores' => json_encode(['median_income' => 0.72, 'employment_rate' => 0.68]),
            'top_positive' => json_encode(['median_income']),
            'top_negative' => json_encode([]),
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('project:scores-to-h3', ['--year' => 2024])
            ->assertExitCode(0);
    }

    public function test_build_deso_h3_mapping_creates_rows(): void
    {
        $this->insertDesoWithGeometry('0114A0010', '0114', '01');

        $this->artisan('build:deso-h3-mapping', ['--resolution' => 8])
            ->assertExitCode(0);

        $count = DB::table('deso_h3_mapping')->where('resolution', 8)->count();
        $this->assertGreaterThan(0, $count);

        $desoCount = DB::table('deso_h3_mapping')
            ->where('resolution', 8)
            ->distinct('deso_code')
            ->count('deso_code');
        $this->assertEquals(1, $desoCount);
    }

    public function test_project_scores_to_h3_populates_h3_scores(): void
    {
        $this->seedH3Data();

        $h3Count = DB::table('h3_scores')->where('year', 2024)->count();
        $this->assertGreaterThan(0, $h3Count);

        $sample = DB::table('h3_scores')->where('year', 2024)->first();
        $this->assertEquals(65.50, (float) $sample->score_raw);
        $this->assertEquals('0114A0010', $sample->primary_deso_code);
        $this->assertNotNull($sample->factor_scores);
    }

    public function test_smooth_h3_scores_applies_smoothing(): void
    {
        $this->seedH3Data();

        $this->artisan('smooth:h3-scores', ['--year' => 2024, '--config' => 'Light'])
            ->assertExitCode(0);

        $sample = DB::table('h3_scores')
            ->where('year', 2024)
            ->whereNotNull('score_smoothed')
            ->first();

        $this->assertNotNull($sample);
        $this->assertNotNull($sample->score_smoothed);
    }

    public function test_smooth_h3_scores_none_config_copies_raw(): void
    {
        $this->seedH3Data();

        $this->artisan('smooth:h3-scores', ['--year' => 2024, '--config' => 'None'])
            ->assertExitCode(0);

        $scores = DB::table('h3_scores')
            ->where('year', 2024)
            ->whereNotNull('score_smoothed')
            ->get();

        $this->assertGreaterThan(0, $scores->count());

        foreach ($scores as $s) {
            $this->assertEquals((float) $s->score_raw, (float) $s->score_smoothed);
        }
    }

    public function test_viewport_endpoint_returns_h3_scores(): void
    {
        $this->seedH3Data();
        $this->artisan('smooth:h3-scores', ['--year' => 2024, '--config' => 'Light']);

        $response = $this->getJson('/api/h3/viewport?bbox=17.9,59.2,18.2,59.5&zoom=11&year=2024&smoothed=true');

        $response->assertOk();
        $response->assertJsonStructure([
            'resolution',
            'count',
            'features',
        ]);
        $response->assertJsonPath('resolution', 8);

        $data = $response->json();
        $this->assertGreaterThan(0, $data['count']);

        $feature = $data['features'][0];
        $this->assertArrayHasKey('h3_index', (array) $feature);
        $this->assertArrayHasKey('score', (array) $feature);
    }

    public function test_viewport_endpoint_aggregates_at_low_zoom(): void
    {
        $this->seedH3Data();
        $this->artisan('smooth:h3-scores', ['--year' => 2024, '--config' => 'Light']);

        $response = $this->getJson('/api/h3/viewport?bbox=17.9,59.2,18.2,59.5&zoom=5&year=2024&smoothed=true');

        $response->assertOk();
        $response->assertJsonPath('resolution', 5);
    }

    public function test_viewport_endpoint_validates_bbox(): void
    {
        $response = $this->getJson('/api/h3/viewport?zoom=8&year=2024');

        $response->assertStatus(422);
    }

    public function test_smoothing_configs_endpoint_returns_presets(): void
    {
        $response = $this->getJson('/api/h3/smoothing-configs');

        $response->assertOk();

        $data = $response->json();
        $this->assertCount(4, $data);

        $names = array_column($data, 'name');
        $this->assertContains('None', $names);
        $this->assertContains('Light', $names);
        $this->assertContains('Medium', $names);
        $this->assertContains('Strong', $names);
    }

    public function test_spatial_smoothing_service_with_no_smoothing(): void
    {
        $this->seedH3Data();

        $config = SmoothingConfig::query()->where('name', 'None')->first();
        $service = new SpatialSmoothingService;

        $updated = $service->smooth(2024, 8, $config);
        $this->assertGreaterThan(0, $updated);

        $scores = DB::table('h3_scores')
            ->where('year', 2024)
            ->whereNotNull('score_smoothed')
            ->get();

        foreach ($scores as $s) {
            $this->assertEquals((float) $s->score_raw, (float) $s->score_smoothed);
        }
    }

    public function test_scores_endpoint_returns_all_h3_scores(): void
    {
        $this->seedH3Data();
        $this->artisan('smooth:h3-scores', ['--year' => 2024, '--config' => 'None']);

        $response = $this->getJson('/api/h3/scores?year=2024&smoothed=true');

        $response->assertOk();

        $data = $response->json();
        $this->assertGreaterThan(0, count($data));
        $this->assertArrayHasKey('h3_index', (array) $data[0]);
        $this->assertArrayHasKey('score', (array) $data[0]);
    }

    public function test_compute_scores_chains_h3_projection(): void
    {
        $this->insertDesoWithGeometry('0114A0010', '0114', '01');

        $this->artisan('build:deso-h3-mapping', ['--resolution' => 8])
            ->assertExitCode(0);

        $indicatorId = DB::table('indicators')->insertGetId([
            'slug' => 'test_indicator',
            'name' => 'Test Indicator',
            'source' => 'test',
            'direction' => 'positive',
            'weight' => 1.0,
            'normalization' => 'rank_percentile',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('indicator_values')->insert([
            'deso_code' => '0114A0010',
            'indicator_id' => $indicatorId,
            'year' => 2024,
            'raw_value' => 50000,
            'normalized_value' => 0.75,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('compute:scores', ['--year' => 2024])
            ->assertExitCode(0);

        $h3Count = DB::table('h3_scores')->where('year', 2024)->count();
        $this->assertGreaterThan(0, $h3Count);

        $smoothedCount = DB::table('h3_scores')
            ->where('year', 2024)
            ->whereNotNull('score_smoothed')
            ->count();
        $this->assertGreaterThan(0, $smoothedCount);
    }

    public function test_viewport_returns_raw_scores_when_smoothed_false(): void
    {
        $this->seedH3Data();
        $this->artisan('smooth:h3-scores', ['--year' => 2024, '--config' => 'Light']);

        $response = $this->getJson('/api/h3/viewport?bbox=17.9,59.2,18.2,59.5&zoom=11&year=2024&smoothed=false');

        $response->assertOk();
        $data = $response->json();
        $this->assertGreaterThan(0, $data['count']);
    }

    public function test_smoothing_pre_aggregates_lower_resolutions(): void
    {
        $this->seedH3Data();
        $this->artisan('smooth:h3-scores', ['--year' => 2024, '--config' => 'Light']);

        foreach ([5, 6, 7] as $res) {
            $count = DB::table('h3_scores')
                ->where('year', 2024)
                ->where('resolution', $res)
                ->count();
            $this->assertGreaterThan(0, $count, "No pre-aggregated scores at resolution {$res}");
        }

        // Verify low-zoom viewport uses pre-aggregated scores
        $response = $this->getJson('/api/h3/viewport?bbox=17.9,59.2,18.2,59.5&zoom=5&year=2024&smoothed=true');
        $response->assertOk();
        $data = $response->json();
        $this->assertGreaterThan(0, $data['count']);
        $this->assertEquals(5, $data['resolution']);
    }
}
