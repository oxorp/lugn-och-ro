<?php

namespace Tests\Feature;

use App\Services\IsochroneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IsochroneServiceTest extends TestCase
{
    use RefreshDatabase;

    private IsochroneService $service;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        $this->service = app(IsochroneService::class);
        Cache::flush();
    }

    private function fakeValhallaIsochrone(array $features = []): void
    {
        if (empty($features)) {
            $features = [
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[18.06, 59.33], [18.08, 59.33], [18.08, 59.34], [18.06, 59.34], [18.06, 59.33]]],
                    ],
                    'properties' => ['contour' => 15],
                ],
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[18.065, 59.332], [18.075, 59.332], [18.075, 59.338], [18.065, 59.338], [18.065, 59.332]]],
                    ],
                    'properties' => ['contour' => 10],
                ],
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[18.067, 59.334], [18.073, 59.334], [18.073, 59.336], [18.067, 59.336], [18.067, 59.334]]],
                    ],
                    'properties' => ['contour' => 5],
                ],
            ];
        }

        Http::fake([
            '*/isochrone' => Http::response([
                'type' => 'FeatureCollection',
                'features' => $features,
            ], 200),
        ]);
    }

    private function fakeValhallaMatrix(array $times): void
    {
        $targets = array_map(fn ($t) => ['time' => $t, 'distance' => ($t ?? 0) * 1.2], $times);

        Http::fake([
            '*/sources_to_targets' => Http::response([
                'sources_to_targets' => [$targets],
            ], 200),
        ]);
    }

    // ─── generate() ─────────────────────────────────────────

    public function test_generate_returns_geojson_feature_collection(): void
    {
        $this->fakeValhallaIsochrone();

        $result = $this->service->generate(59.33, 18.07);

        $this->assertNotNull($result);
        $this->assertEquals('FeatureCollection', $result['type']);
        $this->assertCount(3, $result['features']);
    }

    public function test_generate_adds_area_km2_to_features(): void
    {
        $this->fakeValhallaIsochrone();

        $result = $this->service->generate(59.33, 18.07);

        foreach ($result['features'] as $feature) {
            $this->assertArrayHasKey('area_km2', $feature['properties']);
            $this->assertIsFloat($feature['properties']['area_km2']);
        }
    }

    public function test_generate_caches_results_by_grid_cell(): void
    {
        $this->fakeValhallaIsochrone();

        $result1 = $this->service->generate(59.3301, 18.0701);
        $result2 = $this->service->generate(59.3304, 18.0704);

        // Same grid cell (rounded to 3 decimals: 59.330, 18.070)
        $this->assertEquals($result1, $result2);

        // Only one HTTP call should have been made
        Http::assertSentCount(1);
    }

    public function test_generate_returns_null_on_valhalla_failure(): void
    {
        Http::fake([
            '*/isochrone' => Http::response('Server Error', 500),
        ]);

        $result = $this->service->generate(59.33, 18.07);
        $this->assertNull($result);
    }

    public function test_generate_returns_null_on_timeout(): void
    {
        Http::fake([
            '*/isochrone' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out'),
        ]);

        $result = $this->service->generate(59.33, 18.07);
        $this->assertNull($result);
    }

    public function test_generate_uses_custom_costing_and_contours(): void
    {
        $this->fakeValhallaIsochrone();

        $this->service->generate(59.33, 18.07, 'auto', [5, 10]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['costing'] === 'auto'
                && count($body['contours']) === 2
                && $body['contours'][0]['time'] === 5;
        });
    }

    // ─── outermostPolygonWkt() ───────────────────────────────

    public function test_outermost_polygon_wkt_returns_wkt_string(): void
    {
        $this->fakeValhallaIsochrone();

        $wkt = $this->service->outermostPolygonWkt(59.33, 18.07);

        $this->assertNotNull($wkt);
        $this->assertStringStartsWith('POLYGON(', $wkt);
        $this->assertStringContainsString('18.06', $wkt);
    }

    public function test_outermost_polygon_wkt_returns_null_when_valhalla_down(): void
    {
        Http::fake([
            '*/isochrone' => Http::response('Error', 500),
        ]);

        $wkt = $this->service->outermostPolygonWkt(59.33, 18.07);
        $this->assertNull($wkt);
    }

    public function test_outermost_polygon_wkt_handles_multipolygon(): void
    {
        Http::fake([
            '*/isochrone' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'geometry' => [
                            'type' => 'MultiPolygon',
                            'coordinates' => [
                                [[[18.06, 59.33], [18.08, 59.33], [18.08, 59.34], [18.06, 59.34], [18.06, 59.33]]],
                                [[[18.10, 59.33], [18.12, 59.33], [18.12, 59.34], [18.10, 59.34], [18.10, 59.33]]],
                            ],
                        ],
                        'properties' => ['contour' => 15],
                    ],
                ],
            ], 200),
        ]);

        $wkt = $this->service->outermostPolygonWkt(59.33, 18.07);

        $this->assertNotNull($wkt);
        $this->assertStringStartsWith('POLYGON(', $wkt);
    }

    // ─── travelTimes() ──────────────────────────────────────

    public function test_travel_times_returns_seconds_per_target(): void
    {
        $this->fakeValhallaMatrix([120, 240, 360]);

        $targets = [
            ['lat' => 59.331, 'lng' => 18.071],
            ['lat' => 59.332, 'lng' => 18.072],
            ['lat' => 59.333, 'lng' => 18.073],
        ];

        $times = $this->service->travelTimes(59.33, 18.07, $targets);

        $this->assertCount(3, $times);
        $this->assertEquals(120, $times[0]);
        $this->assertEquals(240, $times[1]);
        $this->assertEquals(360, $times[2]);
    }

    public function test_travel_times_returns_null_for_unreachable_targets(): void
    {
        $this->fakeValhallaMatrix([120, null, 360]);

        $targets = [
            ['lat' => 59.331, 'lng' => 18.071],
            ['lat' => 59.332, 'lng' => 18.072],
            ['lat' => 59.333, 'lng' => 18.073],
        ];

        $times = $this->service->travelTimes(59.33, 18.07, $targets);

        $this->assertCount(3, $times);
        $this->assertEquals(120, $times[0]);
        $this->assertNull($times[1]);
        $this->assertEquals(360, $times[2]);
    }

    public function test_travel_times_returns_empty_for_no_targets(): void
    {
        $times = $this->service->travelTimes(59.33, 18.07, []);
        $this->assertEmpty($times);
    }

    public function test_travel_times_returns_nulls_on_failure(): void
    {
        Http::fake([
            '*/sources_to_targets' => Http::response('Error', 500),
        ]);

        $targets = [
            ['lat' => 59.331, 'lng' => 18.071],
            ['lat' => 59.332, 'lng' => 18.072],
        ];

        $times = $this->service->travelTimes(59.33, 18.07, $targets);

        $this->assertCount(2, $times);
        $this->assertNull($times[0]);
        $this->assertNull($times[1]);
    }

    public function test_travel_times_chunks_large_target_lists(): void
    {
        $targets = [];
        for ($i = 0; $i < 75; $i++) {
            $targets[] = ['lat' => 59.33 + $i * 0.001, 'lng' => 18.07];
        }

        Http::fake([
            '*/sources_to_targets' => Http::sequence()
                ->push([
                    'sources_to_targets' => [array_fill(0, 50, ['time' => 100, 'distance' => 120])],
                ])
                ->push([
                    'sources_to_targets' => [array_fill(0, 25, ['time' => 200, 'distance' => 240])],
                ]),
        ]);

        $times = $this->service->travelTimes(59.33, 18.07, $targets);

        $this->assertCount(75, $times);
        $this->assertEquals(100, $times[0]);
        $this->assertEquals(200, $times[50]);

        Http::assertSentCount(2);
    }

    // ─── LocationController integration ─────────────────────

    public function test_location_api_returns_isochrone_when_enabled(): void
    {
        config(['proximity.isochrone.enabled' => true]);

        $this->fakeValhallaIsochrone();

        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        \App\Models\CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 72.5,
            'computed_at' => now(),
        ]);

        $user = \App\Models\User::factory()->create(['is_admin' => true]);
        $response = $this->actingAs($user)->getJson('/api/location/59.335,18.06');

        $response->assertOk();
        $data = $response->json();

        $this->assertNotNull($data['isochrone']);
        $this->assertEquals('FeatureCollection', $data['isochrone']['type']);
        $this->assertNotNull($data['isochrone_mode']);
    }

    public function test_location_api_returns_null_isochrone_when_disabled(): void
    {
        config(['proximity.isochrone.enabled' => false]);

        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        \App\Models\CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 72.5,
            'computed_at' => now(),
        ]);

        $user = \App\Models\User::factory()->create(['is_admin' => true]);
        $response = $this->actingAs($user)->getJson('/api/location/59.335,18.06');

        $response->assertOk();
        $this->assertNull($response->json('isochrone'));
        $this->assertNull($response->json('isochrone_mode'));
    }

    public function test_location_api_falls_back_when_valhalla_down(): void
    {
        config(['proximity.isochrone.enabled' => true]);

        Http::fake([
            '*/isochrone' => Http::response('Error', 500),
        ]);

        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        \App\Models\CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 72.5,
            'computed_at' => now(),
        ]);

        $user = \App\Models\User::factory()->create(['is_admin' => true]);
        $response = $this->actingAs($user)->getJson('/api/location/59.335,18.06');

        $response->assertOk();
        $this->assertNull($response->json('isochrone'));
        $this->assertNotNull($response->json('display_radius'));
    }

    // ─── Scoring mode in details ────────────────────────────

    public function test_radius_fallback_includes_scoring_mode(): void
    {
        config(['proximity.isochrone.enabled' => false]);

        $service = app(\App\Services\ProximityScoreService::class);
        $result = $service->score(59.335, 18.060);

        foreach ([$result->school, $result->greenSpace, $result->transit, $result->grocery, $result->negativePoi, $result->positivePoi] as $factor) {
            $this->assertEquals('radius', $factor->details['scoring_mode'] ?? null, "Factor {$factor->slug} should have scoring_mode=radius");
        }
    }

    // ─── Helpers ────────────────────────────────────────────

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
}
