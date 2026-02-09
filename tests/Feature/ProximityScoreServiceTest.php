<?php

namespace Tests\Feature;

use App\Models\CompositeScore;
use App\Models\Poi;
use App\Models\PoiCategory;
use App\Models\School;
use App\Models\SchoolStatistic;
use App\Models\User;
use App\Services\ProximityScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProximityScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProximityScoreService $service;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        $this->service = app(ProximityScoreService::class);
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

    // ─── School Proximity ─────────────────────────────────

    public function test_school_proximity_scores_high_for_close_quality_school(): void
    {
        $school = School::factory()->create([
            'name' => 'Vasaskolan',
            'type_of_schooling' => 'Grundskola',
            'status' => 'active',
            'lat' => 59.335,
            'lng' => 18.061,
        ]);
        $this->setGeom('schools', 59.335, 18.061);

        SchoolStatistic::factory()->create([
            'school_unit_code' => $school->school_unit_code,
            'academic_year' => '2023/24',
            'merit_value_17' => 260.0,
        ]);

        $result = $this->service->score(59.335, 18.060);
        $schoolFactor = $result->school;

        $this->assertEquals('prox_school', $schoolFactor->slug);
        $this->assertGreaterThan(70, $schoolFactor->score);
        $this->assertEquals('Vasaskolan', $schoolFactor->details['nearest_school']);
        $this->assertArrayHasKey('nearest_distance_m', $schoolFactor->details);
    }

    public function test_school_proximity_returns_zero_when_no_schools_nearby(): void
    {
        $result = $this->service->score(65.0, 18.0);
        $this->assertEquals(0, $result->school->score);
    }

    public function test_school_proximity_decays_with_distance(): void
    {
        // Place school ~800m away (within effective range with safety modulation)
        $school = School::factory()->create([
            'name' => 'Distant School',
            'type_of_schooling' => 'Grundskola',
            'status' => 'active',
            'lat' => 59.342,
            'lng' => 18.060,
        ]);
        $this->setGeom('schools', 59.342, 18.060);

        SchoolStatistic::factory()->create([
            'school_unit_code' => $school->school_unit_code,
            'merit_value_17' => 260.0,
        ]);

        $result = $this->service->score(59.335, 18.060);
        $this->assertLessThan(50, $result->school->score);
        $this->assertGreaterThan(0, $result->school->score);
    }

    public function test_school_proximity_gives_partial_credit_without_merit_data(): void
    {
        School::factory()->create([
            'name' => 'No Stats School',
            'type_of_schooling' => 'Grundskola',
            'status' => 'active',
            'lat' => 59.336,
            'lng' => 18.061,
        ]);
        $this->setGeom('schools', 59.336, 18.061);

        $result = $this->service->score(59.335, 18.060);
        $this->assertGreaterThan(0, $result->school->score);
        $this->assertLessThanOrEqual(50, $result->school->score);
        $this->assertFalse($result->school->details['merit_data']);
    }

    // ─── Green Space ──────────────────────────────────────

    public function test_green_space_scores_high_for_close_park(): void
    {
        $this->createPoiCategory('park', 'positive');

        $poi = Poi::factory()->create([
            'name' => 'Tantolunden',
            'category' => 'park',
            'lat' => 59.335,
            'lng' => 18.062,
            'status' => 'active',
        ]);
        $this->setGeom('pois', 59.335, 18.062, $poi->id);

        $result = $this->service->score(59.335, 18.060);
        $this->assertGreaterThan(80, $result->greenSpace->score);
        $this->assertEquals('Tantolunden', $result->greenSpace->details['nearest_park']);
    }

    public function test_green_space_returns_zero_when_no_parks(): void
    {
        $result = $this->service->score(59.335, 18.060);
        $this->assertEquals(0, $result->greenSpace->score);
    }

    // ─── Transit ──────────────────────────────────────────

    public function test_transit_scores_high_for_close_stops(): void
    {
        $this->createPoiCategory('public_transport_stop', 'positive');

        $poi = Poi::factory()->create([
            'name' => 'Zinkensdamm',
            'category' => 'public_transport_stop',
            'subcategory' => 'station',
            'lat' => 59.336,
            'lng' => 18.061,
            'status' => 'active',
        ]);
        $this->setGeom('pois', 59.336, 18.061, $poi->id);

        $result = $this->service->score(59.335, 18.060);
        $this->assertGreaterThan(50, $result->transit->score);
        $this->assertEquals('Zinkensdamm', $result->transit->details['nearest_stop']);
    }

    public function test_transit_returns_zero_when_no_stops(): void
    {
        $result = $this->service->score(59.335, 18.060);
        $this->assertEquals(0, $result->transit->score);
    }

    public function test_transit_gives_mode_bonus_for_rail(): void
    {
        $this->createPoiCategory('public_transport_stop', 'positive');

        $bus = Poi::factory()->create([
            'name' => 'Bus Stop',
            'category' => 'public_transport_stop',
            'subcategory' => 'bus_stop',
            'lat' => 59.336,
            'lng' => 18.061,
            'status' => 'active',
        ]);
        $this->setGeom('pois', 59.336, 18.061, $bus->id);

        $busResult = $this->service->score(59.335, 18.060);
        $busScore = $busResult->transit->score;

        DB::table('pois')->delete();

        $train = Poi::factory()->create([
            'name' => 'Train Station',
            'category' => 'public_transport_stop',
            'subcategory' => 'station',
            'lat' => 59.336,
            'lng' => 18.061,
            'status' => 'active',
        ]);
        $this->setGeom('pois', 59.336, 18.061, $train->id);

        $trainResult = $this->service->score(59.335, 18.060);
        $this->assertGreaterThan($busScore, $trainResult->transit->score);
    }

    // ─── Grocery ──────────────────────────────────────────

    public function test_grocery_scores_high_for_close_store(): void
    {
        $this->createPoiCategory('grocery', 'positive');

        $poi = Poi::factory()->grocery()->create([
            'name' => 'ICA Nära',
            'lat' => 59.336,
            'lng' => 18.061,
        ]);
        $this->setGeom('pois', 59.336, 18.061, $poi->id);

        $result = $this->service->score(59.335, 18.060);
        $this->assertGreaterThan(80, $result->grocery->score);
        $this->assertEquals('ICA Nära', $result->grocery->details['nearest_store']);
    }

    // ─── Negative POIs ────────────────────────────────────

    public function test_negative_poi_scores_100_when_none_nearby(): void
    {
        $result = $this->service->score(59.335, 18.060);
        $this->assertEquals(100, $result->negativePoi->score);
        $this->assertEquals(0, $result->negativePoi->details['count']);
    }

    public function test_negative_poi_penalizes_for_close_negative_pois(): void
    {
        $this->createPoiCategory('gambling', 'negative');

        $poi = Poi::factory()->gambling()->create([
            'name' => 'Casino',
            'lat' => 59.3352,
            'lng' => 18.0605,
            'status' => 'active',
        ]);
        $this->setGeom('pois', 59.3352, 18.0605, $poi->id);

        $result = $this->service->score(59.335, 18.060);
        $this->assertLessThan(100, $result->negativePoi->score);
        $this->assertEquals(1, $result->negativePoi->details['count']);
    }

    // ─── Positive POIs ────────────────────────────────────

    public function test_positive_poi_returns_zero_when_none_nearby(): void
    {
        $result = $this->service->score(59.335, 18.060);
        $this->assertEquals(0, $result->positivePoi->score);
    }

    public function test_positive_poi_scores_for_nearby_amenities(): void
    {
        $this->createPoiCategory('restaurant', 'positive');

        for ($i = 0; $i < 5; $i++) {
            $poi = Poi::factory()->create([
                'category' => 'restaurant',
                'lat' => 59.335 + ($i * 0.001),
                'lng' => 18.061,
                'status' => 'active',
            ]);
            $this->setGeom('pois', 59.335 + ($i * 0.001), 18.061, $poi->id);
        }

        $result = $this->service->score(59.335, 18.060);
        $this->assertGreaterThan(0, $result->positivePoi->score);
        $this->assertArrayHasKey('count', $result->positivePoi->details);
    }

    // ─── Composite Score ──────────────────────────────────

    public function test_composite_score_is_between_0_and_100(): void
    {
        $result = $this->service->score(59.335, 18.060);
        $composite = $result->compositeScore();
        $this->assertGreaterThanOrEqual(0, $composite);
        $this->assertLessThanOrEqual(100, $composite);
    }

    public function test_to_array_returns_expected_structure(): void
    {
        $result = $this->service->score(59.335, 18.060);
        $array = $result->toArray();

        $this->assertArrayHasKey('composite', $array);
        $this->assertArrayHasKey('factors', $array);
        $this->assertCount(6, $array['factors']);

        foreach ($array['factors'] as $factor) {
            $this->assertArrayHasKey('slug', $factor);
            $this->assertArrayHasKey('score', $factor);
            $this->assertArrayHasKey('details', $factor);
        }
    }

    // ─── API Integration ──────────────────────────────────

    public function test_location_api_returns_proximity_data(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 72.5,
            'computed_at' => now(),
        ]);

        $user = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($user)->getJson('/api/location/59.335,18.06');

        $response->assertOk()
            ->assertJsonStructure([
                'score' => ['value', 'area_score', 'proximity_score', 'label'],
                'proximity' => ['composite', 'factors'],
            ]);

        $data = $response->json();
        $this->assertNotNull($data['score']['area_score']);
        $this->assertNotNull($data['score']['proximity_score']);
        $this->assertIsNumeric($data['proximity']['composite']);
        $this->assertCount(6, $data['proximity']['factors']);
    }

    public function test_location_api_public_tier_has_null_proximity(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 65.0,
            'computed_at' => now(),
        ]);

        $response = $this->getJson('/api/location/59.335,18.06');

        $response->assertOk()
            ->assertJsonPath('proximity', null)
            ->assertJsonPath('tier', 0);
    }

    public function test_blended_score_uses_70_30_split(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 80.0,
            'computed_at' => now(),
        ]);

        $user = User::factory()->create(['is_admin' => true]);
        $response = $this->actingAs($user)->getJson('/api/location/59.335,18.06');

        $data = $response->json();
        $areaScore = $data['score']['area_score'];
        $proxScore = $data['score']['proximity_score'];
        $blended = $data['score']['value'];

        $expected = round($areaScore * 0.70 + $proxScore * 0.30, 1);
        $this->assertEquals($expected, $blended);
    }

    // ─── Urbanity-Aware Radii ─────────────────────────────

    public function test_urbanity_tier_returned_in_proximity_result(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm', 'urban');

        $result = $this->service->score(59.335, 18.06);
        $array = $result->toArray();

        $this->assertEquals('urban', $result->urbanityTier);
        $this->assertEquals('urban', $array['urbanity_tier']);
    }

    public function test_rural_tier_uses_wider_school_radius(): void
    {
        // Create a rural DeSO and place a school at ~1.8km
        // Urban radius = 1500m (out of range), rural = 3500m (in range)
        $this->createDesoWithGeom('2584C1010', 'Kiruna', 'rural');

        $school = School::factory()->create([
            'name' => 'Rural School',
            'type_of_schooling' => 'Grundskola',
            'status' => 'active',
            'lat' => 59.351,  // ~1.8km from 59.335
            'lng' => 18.060,
        ]);
        $this->setGeom('schools', 59.351, 18.060);

        SchoolStatistic::factory()->create([
            'school_unit_code' => $school->school_unit_code,
            'merit_value_17' => 240.0,
        ]);

        $result = $this->service->score(59.335, 18.06);

        $this->assertEquals('rural', $result->urbanityTier);
        $this->assertGreaterThan(0, $result->school->score, 'Rural should find school within 3.5km');
    }

    public function test_urban_tier_uses_tighter_school_radius(): void
    {
        // School at ~1.8km — beyond urban 1500m radius
        $this->createDesoWithGeom('0180C1090', 'Stockholm', 'urban');

        $school = School::factory()->create([
            'name' => 'Far School',
            'type_of_schooling' => 'Grundskola',
            'status' => 'active',
            'lat' => 59.351,  // ~1.8km from 59.335
            'lng' => 18.060,
        ]);
        $this->setGeom('schools', 59.351, 18.060);

        SchoolStatistic::factory()->create([
            'school_unit_code' => $school->school_unit_code,
            'merit_value_17' => 240.0,
        ]);

        $result = $this->service->score(59.335, 18.06);

        $this->assertEquals('urban', $result->urbanityTier);
        $this->assertEquals(0, $result->school->score, 'Urban should not find school beyond 1.5km');
    }

    public function test_fallback_to_semi_urban_when_no_deso(): void
    {
        // No DeSO created — pin in the ocean
        $result = $this->service->score(65.0, 18.0);
        $this->assertEquals('semi_urban', $result->urbanityTier);
    }

    public function test_location_api_returns_urbanity_aware_display_radius(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm', 'urban');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 72.5,
            'computed_at' => now(),
        ]);

        $user = User::factory()->create(['is_admin' => true]);
        $response = $this->actingAs($user)->getJson('/api/location/59.335,18.06');

        $response->assertOk();
        $this->assertEquals(1500, $response->json('display_radius'));
    }

    public function test_location_api_returns_wider_radius_for_rural(): void
    {
        $this->createDesoWithGeom('2584C1010', 'Kiruna', 'rural');

        CompositeScore::create([
            'deso_code' => '2584C1010',
            'year' => 2024,
            'score' => 55.0,
            'computed_at' => now(),
        ]);

        $user = User::factory()->create(['is_admin' => true]);
        $response = $this->actingAs($user)->getJson('/api/location/59.335,18.06');

        $response->assertOk();
        $this->assertEquals(3500, $response->json('display_radius'));
    }

    // ─── Helpers ──────────────────────────────────────────

    private function createDesoWithGeom(string $desoCode, string $kommunName, string $urbanityTier = 'urban'): void
    {
        DB::table('deso_areas')->insert([
            'deso_code' => $desoCode,
            'kommun_code' => '0180',
            'kommun_name' => $kommunName,
            'lan_code' => '01',
            'urbanity_tier' => $urbanityTier,
            'area_km2' => 0.5,
            'geom' => DB::raw("ST_SetSRID(ST_GeomFromText('POLYGON((18.05 59.33, 18.07 59.33, 18.07 59.34, 18.05 59.34, 18.05 59.33))'), 4326)"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPoiCategory(string $slug, string $signal): void
    {
        PoiCategory::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => ucfirst(str_replace('_', ' ', $slug)),
                'signal' => $signal,
                'osm_tags' => [],
                'catchment_km' => 1.0,
                'is_active' => true,
                'show_on_map' => true,
                'color' => '#000000',
                'icon' => 'circle',
            ]
        );
    }

    public function test_cached_score_returns_same_result_for_nearby_coordinates(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        Cache::flush();

        // First call computes fresh
        $result1 = $this->service->scoreCached(59.3351, 18.0601);

        // Second call with coordinates that round to the same grid cell (~100m)
        $result2 = $this->service->scoreCached(59.3354, 18.0604);

        // Both should have identical composite scores (same cache key)
        $this->assertEquals(
            $result1->compositeScore(),
            $result2->compositeScore(),
        );

        // Verify cache key exists
        $this->assertTrue(Cache::has('proximity:59.335,18.06'));
    }
}
