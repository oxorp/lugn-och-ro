<?php

namespace Tests\Feature;

use App\Services\IsochroneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RingGenerationTest extends TestCase
{
    use RefreshDatabase;

    private IsochroneService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(IsochroneService::class);
        Cache::flush();
    }

    private function fakeValhallaIsochrone(array $contourMinutes = [5, 15]): void
    {
        $features = [];
        foreach ($contourMinutes as $minutes) {
            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[18.06, 59.33], [18.08, 59.33], [18.08, 59.34], [18.06, 59.34], [18.06, 59.33]]],
                ],
                'properties' => ['contour' => $minutes],
            ];
        }

        Http::fake([
            '*/isochrone' => Http::response([
                'type' => 'FeatureCollection',
                'features' => $features,
            ], 200),
        ]);
    }

    private function fakeValhallaSequence(array $pedestrianContours, array $autoContours = []): void
    {
        $sequence = Http::sequence();

        // First call will be for pedestrian contours
        if (! empty($pedestrianContours)) {
            $features = [];
            foreach ($pedestrianContours as $minutes) {
                $features[] = [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[18.06, 59.33], [18.08, 59.33], [18.08, 59.34], [18.06, 59.34], [18.06, 59.33]]],
                    ],
                    'properties' => ['contour' => $minutes],
                ];
            }
            $sequence->push([
                'type' => 'FeatureCollection',
                'features' => $features,
            ], 200);
        }

        // Second call will be for auto contours (if any)
        if (! empty($autoContours)) {
            $features = [];
            foreach ($autoContours as $minutes) {
                $features[] = [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[18.04, 59.32], [18.10, 59.32], [18.10, 59.36], [18.04, 59.36], [18.04, 59.32]]],
                    ],
                    'properties' => ['contour' => $minutes],
                ];
            }
            $sequence->push([
                'type' => 'FeatureCollection',
                'features' => $features,
            ], 200);
        }

        Http::fake([
            '*/isochrone' => $sequence,
        ]);
    }

    // ─── Urban areas ────────────────────────────────────────────

    public function test_urban_generates_two_rings(): void
    {
        $this->fakeValhallaIsochrone([5, 15]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'urban',
            walkingDistanceMinutes: 15,
            hasCar: null
        );

        $this->assertNotNull($result);
        $this->assertCount(2, $result['rings']);
    }

    public function test_urban_ring_1_is_5_minutes_pedestrian(): void
    {
        $this->fakeValhallaIsochrone([5, 15]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'urban',
            walkingDistanceMinutes: 15
        );

        $ring1 = collect($result['rings'])->firstWhere('ring', 1);
        $this->assertEquals(5, $ring1['minutes']);
        $this->assertEquals('pedestrian', $ring1['mode']);
    }

    public function test_urban_ring_2_uses_user_preference(): void
    {
        $this->fakeValhallaIsochrone([5, 20]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'urban',
            walkingDistanceMinutes: 20
        );

        $ring2 = collect($result['rings'])->firstWhere('ring', 2);
        $this->assertEquals(20, $ring2['minutes']);
        $this->assertEquals('pedestrian', $ring2['mode']);
    }

    public function test_urban_ring_2_with_10_minute_preference(): void
    {
        $this->fakeValhallaIsochrone([5, 10]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'urban',
            walkingDistanceMinutes: 10
        );

        $ring2 = collect($result['rings'])->firstWhere('ring', 2);
        $this->assertEquals(10, $ring2['minutes']);
    }

    public function test_urban_ring_2_with_30_minute_preference(): void
    {
        $this->fakeValhallaIsochrone([5, 30]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'urban',
            walkingDistanceMinutes: 30
        );

        $ring2 = collect($result['rings'])->firstWhere('ring', 2);
        $this->assertEquals(30, $ring2['minutes']);
    }

    // ─── Semi-urban areas ───────────────────────────────────────

    public function test_semi_urban_generates_two_rings(): void
    {
        $this->fakeValhallaIsochrone([5, 15]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'semi_urban',
            walkingDistanceMinutes: 15
        );

        $this->assertNotNull($result);
        $this->assertCount(2, $result['rings']);
    }

    public function test_semi_urban_ring_1_is_5_minutes_pedestrian(): void
    {
        $this->fakeValhallaIsochrone([5, 15]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'semi_urban',
            walkingDistanceMinutes: 15
        );

        $ring1 = collect($result['rings'])->firstWhere('ring', 1);
        $this->assertEquals(5, $ring1['minutes']);
        $this->assertEquals('pedestrian', $ring1['mode']);
    }

    public function test_semi_urban_ring_2_uses_user_preference(): void
    {
        $this->fakeValhallaIsochrone([5, 20]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'semi_urban',
            walkingDistanceMinutes: 20
        );

        $ring2 = collect($result['rings'])->firstWhere('ring', 2);
        $this->assertEquals(20, $ring2['minutes']);
        $this->assertEquals('pedestrian', $ring2['mode']);
    }

    public function test_semi_urban_behaves_same_as_urban(): void
    {
        $this->fakeValhallaIsochrone([5, 15]);

        $urbanResult = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'urban',
            walkingDistanceMinutes: 15
        );

        Cache::flush();

        $this->fakeValhallaIsochrone([5, 15]);

        $semiUrbanResult = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'semi_urban',
            walkingDistanceMinutes: 15
        );

        $this->assertCount(count($urbanResult['rings']), $semiUrbanResult['rings']);

        $urbanRing2 = collect($urbanResult['rings'])->firstWhere('ring', 2);
        $semiUrbanRing2 = collect($semiUrbanResult['rings'])->firstWhere('ring', 2);

        $this->assertEquals($urbanRing2['minutes'], $semiUrbanRing2['minutes']);
        $this->assertEquals($urbanRing2['mode'], $semiUrbanRing2['mode']);
    }

    // ─── Rural areas with car ───────────────────────────────────

    public function test_rural_with_car_generates_three_rings(): void
    {
        $this->fakeValhallaSequence([5, 15], [10]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: 15,
            hasCar: true
        );

        $this->assertNotNull($result);
        $this->assertCount(3, $result['rings']);
    }

    public function test_rural_with_car_ring_1_is_5_minutes_pedestrian(): void
    {
        $this->fakeValhallaSequence([5, 15], [10]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: 15,
            hasCar: true
        );

        $ring1 = collect($result['rings'])->firstWhere('ring', 1);
        $this->assertEquals(5, $ring1['minutes']);
        $this->assertEquals('pedestrian', $ring1['mode']);
    }

    public function test_rural_with_car_ring_2_uses_user_preference_pedestrian(): void
    {
        $this->fakeValhallaSequence([5, 20], [10]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: 20,
            hasCar: true
        );

        $ring2 = collect($result['rings'])->firstWhere('ring', 2);
        $this->assertEquals(20, $ring2['minutes']);
        $this->assertEquals('pedestrian', $ring2['mode']);
    }

    public function test_rural_with_car_ring_3_is_10_minutes_auto(): void
    {
        $this->fakeValhallaSequence([5, 15], [10]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: 15,
            hasCar: true
        );

        $ring3 = collect($result['rings'])->firstWhere('ring', 3);
        $this->assertEquals(10, $ring3['minutes']);
        $this->assertEquals('auto', $ring3['mode']);
    }

    public function test_rural_with_car_ring_3_is_always_10_minutes_regardless_of_user_preference(): void
    {
        // Test with 30 minute walking preference
        $this->fakeValhallaSequence([5, 30], [10]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: 30,
            hasCar: true
        );

        $ring3 = collect($result['rings'])->firstWhere('ring', 3);
        $this->assertEquals(10, $ring3['minutes'], 'Ring 3 should always be 10 minutes regardless of user preference');
    }

    // ─── Rural areas without car ────────────────────────────────

    public function test_rural_without_car_generates_three_rings(): void
    {
        $this->fakeValhallaIsochrone([5, 7, 15]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: 15,
            hasCar: false
        );

        $this->assertNotNull($result);
        $this->assertCount(3, $result['rings']);
    }

    public function test_rural_without_car_ring_1_is_5_minutes_pedestrian(): void
    {
        $this->fakeValhallaIsochrone([5, 7, 15]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: 15,
            hasCar: false
        );

        $ring1 = collect($result['rings'])->firstWhere('ring', 1);
        $this->assertEquals(5, $ring1['minutes']);
        $this->assertEquals('pedestrian', $ring1['mode']);
    }

    public function test_rural_without_car_ring_2_is_half_user_preference(): void
    {
        $this->fakeValhallaIsochrone([5, 10, 20]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: 20,
            hasCar: false
        );

        $ring2 = collect($result['rings'])->firstWhere('ring', 2);
        $this->assertEquals(10, $ring2['minutes'], '20 min preference / 2 = 10 min');
        $this->assertEquals('pedestrian', $ring2['mode']);
    }

    public function test_rural_without_car_ring_2_with_15_minute_preference(): void
    {
        $this->fakeValhallaIsochrone([5, 7, 15]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: 15,
            hasCar: false
        );

        $ring2 = collect($result['rings'])->firstWhere('ring', 2);
        $this->assertEquals(7, $ring2['minutes'], '15 min preference / 2 = 7 min (floor)');
    }

    public function test_rural_without_car_ring_2_has_minimum_of_5_minutes(): void
    {
        $this->fakeValhallaIsochrone([5, 5, 10]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: 10,
            hasCar: false
        );

        $ring2 = collect($result['rings'])->firstWhere('ring', 2);
        // 10 min / 2 = 5 min, but minimum is 5
        $this->assertGreaterThanOrEqual(5, $ring2['minutes'], 'Ring 2 should have minimum of 5 minutes');
    }

    public function test_rural_without_car_ring_3_is_full_user_preference(): void
    {
        $this->fakeValhallaIsochrone([5, 10, 20]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: 20,
            hasCar: false
        );

        $ring3 = collect($result['rings'])->firstWhere('ring', 3);
        $this->assertEquals(20, $ring3['minutes']);
        $this->assertEquals('pedestrian', $ring3['mode']);
    }

    public function test_rural_without_car_ring_3_is_pedestrian_not_auto(): void
    {
        $this->fakeValhallaIsochrone([5, 7, 15]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: 15,
            hasCar: false
        );

        $ring3 = collect($result['rings'])->firstWhere('ring', 3);
        $this->assertEquals('pedestrian', $ring3['mode'], 'Rural without car should use pedestrian for all rings');
    }

    // ─── Ring labels ────────────────────────────────────────────

    public function test_ring_1_has_correct_swedish_label(): void
    {
        $this->fakeValhallaIsochrone([5, 15]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'urban',
            walkingDistanceMinutes: 15
        );

        $ring1 = collect($result['rings'])->firstWhere('ring', 1);
        $this->assertStringContainsString('5', $ring1['label']);
        $this->assertStringContainsString('promenad', $ring1['label']);
    }

    public function test_pedestrian_ring_label_includes_promenad(): void
    {
        $this->fakeValhallaIsochrone([5, 15]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'urban',
            walkingDistanceMinutes: 15
        );

        $ring2 = collect($result['rings'])->firstWhere('ring', 2);
        $this->assertStringContainsString('promenad', $ring2['label']);
        $this->assertStringContainsString('15', $ring2['label']);
    }

    public function test_auto_ring_label_includes_bil(): void
    {
        $this->fakeValhallaSequence([5, 15], [10]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: 15,
            hasCar: true
        );

        $ring3 = collect($result['rings'])->firstWhere('ring', 3);
        $this->assertStringContainsString('bil', $ring3['label']);
        $this->assertStringContainsString('10', $ring3['label']);
    }

    // ─── Ring colors ────────────────────────────────────────────

    public function test_rings_have_colors(): void
    {
        $this->fakeValhallaIsochrone([5, 15]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'urban',
            walkingDistanceMinutes: 15
        );

        foreach ($result['rings'] as $ring) {
            $this->assertArrayHasKey('color', $ring);
            $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $ring['color']);
        }
    }

    public function test_each_ring_has_distinct_color(): void
    {
        $this->fakeValhallaSequence([5, 15], [10]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: 15,
            hasCar: true
        );

        $colors = array_column($result['rings'], 'color');
        $uniqueColors = array_unique($colors);

        $this->assertCount(count($result['rings']), $uniqueColors, 'Each ring should have a distinct color');
    }

    // ─── GeoJSON output ─────────────────────────────────────────

    public function test_returns_geojson_feature_collection(): void
    {
        $this->fakeValhallaIsochrone([5, 15]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'urban',
            walkingDistanceMinutes: 15
        );

        $this->assertEquals('FeatureCollection', $result['geojson']['type']);
        $this->assertNotEmpty($result['geojson']['features']);
    }

    public function test_geojson_features_have_ring_metadata(): void
    {
        $this->fakeValhallaIsochrone([5, 15]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'urban',
            walkingDistanceMinutes: 15
        );

        foreach ($result['geojson']['features'] as $feature) {
            $this->assertArrayHasKey('ring', $feature['properties']);
            $this->assertArrayHasKey('mode', $feature['properties']);
            $this->assertArrayHasKey('label', $feature['properties']);
            $this->assertArrayHasKey('color', $feature['properties']);
        }
    }

    public function test_geojson_features_are_sorted_outermost_first(): void
    {
        $this->fakeValhallaSequence([5, 15], [10]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: 15,
            hasCar: true
        );

        $features = $result['geojson']['features'];
        $ringNumbers = array_column(array_column($features, 'properties'), 'ring');

        // Should be sorted descending (3, 2, 1) for proper map layering
        $this->assertEquals($ringNumbers, array_reverse(array_values(array_sort($ringNumbers))));
    }

    // ─── Error handling ─────────────────────────────────────────

    public function test_returns_null_when_valhalla_fails(): void
    {
        Http::fake([
            '*/isochrone' => Http::response('Server Error', 500),
        ]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'urban',
            walkingDistanceMinutes: 15
        );

        $this->assertNull($result);
    }

    public function test_returns_null_when_valhalla_returns_empty_features(): void
    {
        Http::fake([
            '*/isochrone' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [],
            ], 200),
        ]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'urban',
            walkingDistanceMinutes: 15
        );

        $this->assertNull($result);
    }

    // ─── Default values ─────────────────────────────────────────

    public function test_defaults_to_urban_when_tier_not_specified(): void
    {
        $this->fakeValhallaIsochrone([5, 15]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
        );

        // Urban has 2 rings
        $this->assertCount(2, $result['rings']);
    }

    public function test_defaults_to_15_minute_walking_preference(): void
    {
        $this->fakeValhallaIsochrone([5, 15]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'urban'
        );

        $ring2 = collect($result['rings'])->firstWhere('ring', 2);
        $this->assertEquals(15, $ring2['minutes']);
    }

    // ─── Valhalla API calls ─────────────────────────────────────

    public function test_urban_only_makes_pedestrian_valhalla_call(): void
    {
        $this->fakeValhallaIsochrone([5, 15]);

        $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'urban',
            walkingDistanceMinutes: 15
        );

        Http::assertSent(function ($request) {
            return $request->data()['costing'] === 'pedestrian';
        });

        Http::assertSentCount(1);
    }

    public function test_rural_with_car_makes_both_pedestrian_and_auto_calls(): void
    {
        $this->fakeValhallaSequence([5, 15], [10]);

        $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: 15,
            hasCar: true
        );

        $requests = Http::recorded();
        $costingModes = array_map(fn ($r) => $r[0]->data()['costing'] ?? null, $requests);

        $this->assertContains('pedestrian', $costingModes);
        $this->assertContains('auto', $costingModes);
        Http::assertSentCount(2);
    }

    public function test_rural_without_car_only_makes_pedestrian_call(): void
    {
        $this->fakeValhallaIsochrone([5, 7, 15]);

        $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: 15,
            hasCar: false
        );

        Http::assertSent(function ($request) {
            return $request->data()['costing'] === 'pedestrian';
        });

        Http::assertSentCount(1);
    }

    // ─── All walking distance options ───────────────────────────

    /**
     * @dataProvider walkingDistanceProvider
     */
    public function test_urban_supports_all_walking_distances(int $minutes): void
    {
        $this->fakeValhallaIsochrone([5, $minutes]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'urban',
            walkingDistanceMinutes: $minutes
        );

        $ring2 = collect($result['rings'])->firstWhere('ring', 2);
        $this->assertEquals($minutes, $ring2['minutes']);
    }

    /**
     * @dataProvider walkingDistanceProvider
     */
    public function test_rural_without_car_halves_all_walking_distances(int $minutes): void
    {
        $expected = max(5, (int) floor($minutes / 2));
        $this->fakeValhallaIsochrone([5, $expected, $minutes]);

        $result = $this->service->generateMultipleRings(
            lat: 59.33,
            lng: 18.07,
            urbanityTier: 'rural',
            walkingDistanceMinutes: $minutes,
            hasCar: false
        );

        $ring2 = collect($result['rings'])->firstWhere('ring', 2);
        $this->assertEquals($expected, $ring2['minutes']);
    }

    public static function walkingDistanceProvider(): array
    {
        return [
            '10 minutes' => [10],
            '15 minutes' => [15],
            '20 minutes' => [20],
            '30 minutes' => [30],
        ];
    }
}
