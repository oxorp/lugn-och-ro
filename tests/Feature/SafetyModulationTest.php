<?php

namespace Tests\Feature;

use App\Models\CompositeScore;
use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Models\Poi;
use App\Models\PoiCategory;
use App\Models\User;
use App\Services\ProximityScoreService;
use App\Services\SafetyScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SafetyModulationTest extends TestCase
{
    use RefreshDatabase;

    private SafetyScoreService $safetyService;

    private ProximityScoreService $proximityService;

    private int $year;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        $this->safetyService = app(SafetyScoreService::class);
        $this->proximityService = app(ProximityScoreService::class);
        $this->year = now()->year - 1;
    }

    // ─── SafetyScoreService ────────────────────────────────

    public function test_safety_score_returns_default_when_no_data(): void
    {
        $score = $this->safetyService->forDeso('UNKNOWN_CODE', $this->year);
        $this->assertEquals(0.5, $score);
    }

    public function test_safety_score_high_for_safe_area(): void
    {
        $this->createDesoWithGeom('0162C1010', 'Danderyd');
        $this->seedSafetyIndicators('0162C1010', $this->year, [
            'employment_rate' => 0.95,      // Very high employment
            'low_economic_standard_pct' => 0.05, // Very low poverty
            'crime_violent_rate' => 0.05,    // Very low crime
            'perceived_safety' => 0.95,      // Very safe
            'vulnerability_flag' => 0.0,     // Not vulnerable
        ]);

        $score = $this->safetyService->forDeso('0162C1010', $this->year);
        $this->assertGreaterThan(0.80, $score);
    }

    public function test_safety_score_low_for_unsafe_area(): void
    {
        $this->createDesoWithGeom('0180C2010', 'Rinkeby');
        $this->seedSafetyIndicators('0180C2010', $this->year, [
            'employment_rate' => 0.10,
            'low_economic_standard_pct' => 0.90,
            'crime_violent_rate' => 0.95,
            'perceived_safety' => 0.10,
            'vulnerability_flag' => 0.95,
        ]);

        $score = $this->safetyService->forDeso('0180C2010', $this->year);
        $this->assertLessThan(0.25, $score);
    }

    public function test_safety_score_works_with_partial_indicators(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');
        // Only provide employment rate — other signals missing
        $indicator = Indicator::create([
            'slug' => 'employment_rate',
            'name' => 'Employment Rate',
            'source' => 'test',
            'direction' => 'positive',
            'weight' => 0.05,
            'normalization' => 'rank_percentile',
            'is_active' => true,
        ]);
        IndicatorValue::create([
            'deso_code' => '0180C1090',
            'indicator_id' => $indicator->id,
            'year' => $this->year,
            'raw_value' => 70.0,
            'normalized_value' => 0.75,
        ]);

        $score = $this->safetyService->forDeso('0180C1090', $this->year);
        // Should work with just one indicator, normalized by available weight
        $this->assertGreaterThan(0.5, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function test_safety_score_is_cached(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');
        $this->seedSafetyIndicators('0180C1090', $this->year, [
            'employment_rate' => 0.80,
            'crime_violent_rate' => 0.20,
        ]);

        $score1 = $this->safetyService->forDeso('0180C1090', $this->year);
        $score2 = $this->safetyService->forDeso('0180C1090', $this->year);
        $this->assertEquals($score1, $score2);

        // Verify it was actually cached
        $this->assertTrue(Cache::has("safety_score:0180C1090:{$this->year}"));
    }

    // ─── Safety-Modulated Decay ────────────────────────────

    public function test_grocery_barely_affected_by_safety(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');
        $this->seedSafetyIndicators('0180C1090', $this->year, [
            'crime_violent_rate' => 0.90,
            'perceived_safety' => 0.10,
            'employment_rate' => 0.10,
        ]);

        $this->createPoiCategory('grocery', 'positive', 0.30);

        $poi = Poi::factory()->grocery()->create([
            'name' => 'ICA Nära',
            'lat' => 59.336,
            'lng' => 18.061,
        ]);
        $this->setGeom('pois', 59.336, 18.061, $poi->id);

        $result = $this->proximityService->score(59.335, 18.060);
        // Grocery should still have a reasonable score even in unsafe area
        $this->assertGreaterThan(30, $result->grocery->score);
    }

    public function test_green_space_heavily_affected_by_safety(): void
    {
        // Test in safe area — park at ~500m
        $this->createDesoWithGeom('0162C1010', 'Danderyd');
        $this->seedSafetyIndicators('0162C1010', $this->year, [
            'employment_rate' => 0.95,
            'low_economic_standard_pct' => 0.05,
            'crime_violent_rate' => 0.05,
            'perceived_safety' => 0.95,
            'vulnerability_flag' => 0.0,
        ]);
        $this->createPoiCategory('park', 'positive', 1.00);

        // Place park ~500m from test point (59.395, 18.030)
        $poi = Poi::factory()->create([
            'name' => 'Danderyds Park',
            'category' => 'park',
            'lat' => 59.3995,
            'lng' => 18.030,
            'status' => 'active',
        ]);
        $this->setGeom('pois', 59.3995, 18.030, $poi->id);

        $safeResult = $this->proximityService->score(59.395, 18.030);
        $safeGreenScore = $safeResult->greenSpace->score;

        // Clean up for unsafe area test
        DB::table('pois')->delete();
        DB::table('deso_areas')->delete();
        DB::table('indicator_values')->delete();
        DB::table('indicators')->delete();
        Cache::flush();
        $this->proximityService = app(ProximityScoreService::class);

        // Test in unsafe area — park at same ~500m distance
        $this->createDesoWithGeom('0180C2010', 'Rinkeby');
        $this->seedSafetyIndicators('0180C2010', $this->year, [
            'employment_rate' => 0.05,
            'low_economic_standard_pct' => 0.95,
            'crime_violent_rate' => 0.95,
            'perceived_safety' => 0.05,
            'vulnerability_flag' => 0.95,
        ]);

        $poi2 = Poi::factory()->create([
            'name' => 'Rinkeby Park',
            'category' => 'park',
            'lat' => 59.3925,
            'lng' => 17.930,
            'status' => 'active',
        ]);
        $this->setGeom('pois', 59.3925, 17.930, $poi2->id);

        $unsafeResult = $this->proximityService->score(59.388, 17.930);
        $unsafeGreenScore = $unsafeResult->greenSpace->score;

        // Park in unsafe area should score lower than same distance in safe area
        $this->assertLessThan($safeGreenScore, $unsafeGreenScore);
    }

    public function test_negative_pois_not_affected_by_safety(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');
        $this->seedSafetyIndicators('0180C1090', $this->year, [
            'crime_violent_rate' => 0.95,
            'perceived_safety' => 0.10,
        ]);
        $this->createPoiCategory('gambling', 'negative', 0.00);

        $poi = Poi::factory()->gambling()->create([
            'name' => 'Casino',
            'lat' => 59.3352,
            'lng' => 18.0605,
            'status' => 'active',
        ]);
        $this->setGeom('pois', 59.3352, 18.0605, $poi->id);

        $result = $this->proximityService->score(59.335, 18.060);
        // Negative POIs use flat decay (no safety modulation)
        $this->assertLessThan(100, $result->negativePoi->score);
    }

    // ─── ProximityResult Safety Zone ───────────────────────

    public function test_proximity_result_includes_safety_score(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');
        $this->seedSafetyIndicators('0180C1090', $this->year, [
            'employment_rate' => 0.50,
        ]);

        $result = $this->proximityService->score(59.335, 18.060);
        $array = $result->toArray();

        $this->assertArrayHasKey('safety_score', $array);
        $this->assertArrayHasKey('safety_zone', $array);
        $this->assertArrayHasKey('level', $array['safety_zone']);
        $this->assertArrayHasKey('label', $array['safety_zone']);
    }

    public function test_safety_zone_high_for_safe_area(): void
    {
        $this->createDesoWithGeom('0162C1010', 'Danderyd');
        $this->seedSafetyIndicators('0162C1010', $this->year, [
            'employment_rate' => 0.95,
            'low_economic_standard_pct' => 0.05,
            'education_below_secondary_pct' => 0.05,
            'crime_violent_rate' => 0.05,
            'crime_total_rate' => 0.05,
            'perceived_safety' => 0.95,
            'vulnerability_flag' => 0.0,
        ]);

        $result = $this->proximityService->score(59.395, 18.030);
        $zone = $result->safetyZone();
        $this->assertEquals('high', $zone['level']);
    }

    public function test_safety_zone_low_for_unsafe_area(): void
    {
        $this->createDesoWithGeom('0180C2010', 'Rinkeby');
        $this->seedSafetyIndicators('0180C2010', $this->year, [
            'employment_rate' => 0.05,
            'low_economic_standard_pct' => 0.95,
            'education_below_secondary_pct' => 0.95,
            'crime_violent_rate' => 0.95,
            'crime_total_rate' => 0.95,
            'perceived_safety' => 0.05,
            'vulnerability_flag' => 0.95,
        ]);

        $result = $this->proximityService->score(59.388, 17.930);
        $zone = $result->safetyZone();
        $this->assertEquals('low', $zone['level']);
    }

    // ─── Effective Distance in Details ─────────────────────

    public function test_factor_details_include_effective_distance(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');
        $this->seedSafetyIndicators('0180C1090', $this->year, [
            'crime_violent_rate' => 0.80,
            'perceived_safety' => 0.20,
        ]);
        $this->createPoiCategory('grocery', 'positive', 0.30);

        $poi = Poi::factory()->grocery()->create([
            'name' => 'ICA Nära',
            'lat' => 59.336,
            'lng' => 18.061,
        ]);
        $this->setGeom('pois', 59.336, 18.061, $poi->id);

        $result = $this->proximityService->score(59.335, 18.060);
        $this->assertArrayHasKey('effective_distance_m', $result->grocery->details);
        // Effective distance should be >= physical distance
        $this->assertGreaterThanOrEqual(
            $result->grocery->details['distance_m'],
            $result->grocery->details['effective_distance_m']
        );
    }

    // ─── Edge Cases ────────────────────────────────────────

    public function test_zero_sensitivity_means_no_modulation(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');
        $this->seedSafetyIndicators('0180C1090', $this->year, [
            'crime_violent_rate' => 0.95,
        ]);
        // Force sensitivity to 0 (no safety effect)
        $this->createPoiCategory('grocery', 'positive', 0.00);

        $poi = Poi::factory()->grocery()->create([
            'name' => 'ICA Nära',
            'lat' => 59.336,
            'lng' => 18.061,
        ]);
        $this->setGeom('pois', 59.336, 18.061, $poi->id);

        $result = $this->proximityService->score(59.335, 18.060);
        // With sensitivity 0, effective = physical
        $this->assertEquals(
            $result->grocery->details['distance_m'],
            $result->grocery->details['effective_distance_m'],
        );
    }

    public function test_max_sensitivity_does_not_produce_negative_scores(): void
    {
        $this->createDesoWithGeom('0180C2010', 'Rinkeby');
        $this->seedSafetyIndicators('0180C2010', $this->year, [
            'crime_violent_rate' => 0.99,
            'perceived_safety' => 0.01,
            'employment_rate' => 0.01,
            'vulnerability_flag' => 0.99,
        ]);
        // Max sensitivity
        $this->createPoiCategory('park', 'positive', 3.00);

        $poi = Poi::factory()->create([
            'name' => 'Park',
            'category' => 'park',
            'lat' => 59.3885,
            'lng' => 17.931,
            'status' => 'active',
        ]);
        $this->setGeom('pois', 59.3885, 17.931, $poi->id);

        $result = $this->proximityService->score(59.388, 17.930);
        $this->assertGreaterThanOrEqual(0, $result->greenSpace->score);
    }

    public function test_point_outside_deso_gets_default_safety(): void
    {
        // No DeSO created — point outside any area
        $result = $this->proximityService->score(65.0, 18.0);
        $array = $result->toArray();
        $this->assertEquals(0.5, $array['safety_score']);
    }

    // ─── API Integration ──────────────────────────────────

    public function test_location_api_returns_safety_zone(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');
        $this->seedSafetyIndicators('0180C1090', $this->year, [
            'employment_rate' => 0.50,
            'crime_violent_rate' => 0.50,
        ]);

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => $this->year,
            'score' => 50.0,
            'computed_at' => now(),
        ]);

        $user = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($user)->getJson('/api/location/59.335,18.06');

        $response->assertOk()
            ->assertJsonStructure([
                'proximity' => [
                    'composite',
                    'safety_score',
                    'safety_zone' => ['level', 'label'],
                    'factors',
                ],
            ]);
    }

    // ─── Admin Controller ──────────────────────────────────

    public function test_admin_poi_categories_page_loads(): void
    {
        $this->withoutVite();
        $user = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($user)->get('/admin/poi-categories');
        $response->assertOk();
    }

    public function test_admin_can_update_safety_sensitivity(): void
    {
        $category = PoiCategory::query()->updateOrCreate(
            ['slug' => 'test_category'],
            [
                'name' => 'Test Category',
                'signal' => 'positive',
                'safety_sensitivity' => 1.00,
                'osm_tags' => [],
                'catchment_km' => 1.0,
                'is_active' => true,
                'show_on_map' => true,
                'color' => '#000000',
                'icon' => 'circle',
            ]
        );

        $user = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($user)->putJson(
            "/admin/poi-categories/{$category->id}/safety",
            [
                'safety_sensitivity' => 0.50,
                'signal' => 'positive',
                'is_active' => true,
            ]
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('poi_categories', [
            'id' => $category->id,
            'safety_sensitivity' => 0.50,
        ]);
    }

    public function test_admin_update_clears_cache(): void
    {
        $category = PoiCategory::query()->updateOrCreate(
            ['slug' => 'test_cache'],
            [
                'name' => 'Test',
                'signal' => 'positive',
                'safety_sensitivity' => 1.00,
                'osm_tags' => [],
                'catchment_km' => 1.0,
                'is_active' => true,
                'show_on_map' => true,
                'color' => '#000000',
                'icon' => 'circle',
            ]
        );

        Cache::put('poi_category_settings', 'old_value', 3600);

        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user)->putJson(
            "/admin/poi-categories/{$category->id}/safety",
            [
                'safety_sensitivity' => 0.70,
                'signal' => 'positive',
                'is_active' => true,
            ]
        );

        $this->assertFalse(Cache::has('poi_category_settings'));
    }

    public function test_admin_validation_rejects_invalid_sensitivity(): void
    {
        $category = PoiCategory::query()->updateOrCreate(
            ['slug' => 'test_val'],
            [
                'name' => 'Test',
                'signal' => 'positive',
                'safety_sensitivity' => 1.00,
                'osm_tags' => [],
                'catchment_km' => 1.0,
                'is_active' => true,
                'show_on_map' => true,
                'color' => '#000000',
                'icon' => 'circle',
            ]
        );

        $user = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($user)->putJson(
            "/admin/poi-categories/{$category->id}/safety",
            [
                'safety_sensitivity' => 5.0, // Over max of 3
                'signal' => 'positive',
                'is_active' => true,
            ]
        );

        $response->assertUnprocessable();
    }

    // ─── Helpers ──────────────────────────────────────────

    private function createDesoWithGeom(string $desoCode, string $kommunName): void
    {
        // Generate appropriate polygon based on deso code
        $coords = match ($desoCode) {
            '0162C1010' => '18.02 59.39, 18.04 59.39, 18.04 59.40, 18.02 59.40, 18.02 59.39',
            '0180C2010' => '17.92 59.385, 17.94 59.385, 17.94 59.395, 17.92 59.395, 17.92 59.385',
            default => '18.05 59.33, 18.07 59.33, 18.07 59.34, 18.05 59.34, 18.05 59.33',
        };

        DB::table('deso_areas')->insert([
            'deso_code' => $desoCode,
            'kommun_code' => substr($desoCode, 0, 4),
            'kommun_name' => $kommunName,
            'lan_code' => substr($desoCode, 0, 2),
            'urbanity_tier' => 'urban',
            'area_km2' => 0.5,
            'geom' => DB::raw("ST_SetSRID(ST_GeomFromText('POLYGON(({$coords}))'), 4326)"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Seed safety-relevant indicators for a DeSO.
     *
     * @param  array<string, float>  $normalizedValues  slug => normalized_value (0-1)
     */
    private function seedSafetyIndicators(string $desoCode, int $year, array $normalizedValues): void
    {
        $slugConfig = [
            'employment_rate' => ['direction' => 'positive'],
            'low_economic_standard_pct' => ['direction' => 'negative'],
            'education_below_secondary_pct' => ['direction' => 'negative'],
            'crime_violent_rate' => ['direction' => 'negative'],
            'crime_total_rate' => ['direction' => 'negative'],
            'perceived_safety' => ['direction' => 'positive'],
            'vulnerability_flag' => ['direction' => 'negative'],
        ];

        foreach ($normalizedValues as $slug => $normalizedValue) {
            $config = $slugConfig[$slug] ?? ['direction' => 'positive'];

            $indicator = Indicator::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => ucwords(str_replace('_', ' ', $slug)),
                    'source' => 'test',
                    'direction' => $config['direction'],
                    'weight' => 0.05,
                    'normalization' => 'rank_percentile',
                    'is_active' => true,
                ]
            );

            IndicatorValue::create([
                'deso_code' => $desoCode,
                'indicator_id' => $indicator->id,
                'year' => $year,
                'raw_value' => $normalizedValue * 100,
                'normalized_value' => $normalizedValue,
            ]);
        }
    }

    private function createPoiCategory(string $slug, string $signal, float $safetySensitivity): void
    {
        PoiCategory::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => ucfirst(str_replace('_', ' ', $slug)),
                'signal' => $signal,
                'safety_sensitivity' => $safetySensitivity,
                'osm_tags' => [],
                'catchment_km' => 1.0,
                'is_active' => true,
                'show_on_map' => true,
                'color' => '#000000',
                'icon' => 'circle',
            ]
        );
    }

    private function setGeom(string $table, float $lat, float $lng, ?int $id = null): void
    {
        $query = DB::table($table);
        if ($id) {
            $query->where('id', $id);
        } else {
            $query->whereNull('geom');
        }
        $query->update(['geom' => DB::raw("ST_SetSRID(ST_MakePoint({$lng}, {$lat}), 4326)")]);
    }
}
