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
        $school = School::factory()->create([
            'name' => 'Distant School',
            'type_of_schooling' => 'Grundskola',
            'status' => 'active',
            'lat' => 59.348,
            'lng' => 18.060,
        ]);
        $this->setGeom('schools', 59.348, 18.060);

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

    // ─── Helpers ──────────────────────────────────────────

    private function createDesoWithGeom(string $desoCode, string $kommunName): void
    {
        DB::table('deso_areas')->insert([
            'deso_code' => $desoCode,
            'kommun_code' => '0180',
            'kommun_name' => $kommunName,
            'lan_code' => '01',
            'urbanity_tier' => 'urban',
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
}
