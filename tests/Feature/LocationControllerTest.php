<?php

namespace Tests\Feature;

use App\Models\CompositeScore;
use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Models\Poi;
use App\Models\PoiCategory;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    private function createDesoWithGeom(string $desoCode, string $kommunName, string $kommunCode = '0180'): void
    {
        DB::table('deso_areas')->insert([
            'deso_code' => $desoCode,
            'kommun_code' => $kommunCode,
            'kommun_name' => $kommunName,
            'lan_code' => substr($kommunCode, 0, 2),
            'urbanity_tier' => 'urban',
            'area_km2' => 0.5,
            'geom' => DB::raw("ST_SetSRID(ST_GeomFromText('POLYGON((18.05 59.33, 18.07 59.33, 18.07 59.34, 18.05 59.34, 18.05 59.33))'), 4326)"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_returns_location_data_for_valid_coordinates(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 72.5,
            'trend_1y' => 3.2,
            'factor_scores' => ['median_income' => 0.85],
            'top_positive' => ['median_income'],
            'top_negative' => ['crime_total_rate'],
            'computed_at' => now(),
        ]);

        $indicator = Indicator::create([
            'slug' => 'median_income',
            'name' => 'Medianinkomst',
            'unit' => 'SEK',
            'direction' => 'positive',
            'weight' => 0.09,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'source' => 'scb',
            'is_active' => true,
            'display_order' => 1,
        ]);

        IndicatorValue::create([
            'deso_code' => '0180C1090',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 287000,
            'normalized_value' => 0.78,
        ]);

        $user = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($user)->getJson('/api/location/59.335,18.06');

        $response->assertOk()
            ->assertJsonStructure([
                'location' => ['lat', 'lng', 'deso_code', 'kommun', 'lan_code', 'area_km2', 'urbanity_tier'],
                'score' => ['value', 'area_score', 'proximity_score', 'trend_1y', 'label', 'top_positive', 'top_negative', 'factor_scores'],
                'tier',
                'display_radius',
                'proximity' => ['composite', 'factors'],
                'indicators',
                'schools',
                'pois',
                'poi_categories',
            ])
            ->assertJsonPath('display_radius', 1500) // urban tier
            ->assertJsonFragment([
                'kommun' => 'Stockholm',
                'deso_code' => '0180C1090',
            ])
            ->assertJsonPath('score.area_score', 72.5);

        // Blended score = area_score * 0.70 + proximity_score * 0.30
        $data = $response->json();
        $expected = round(72.5 * 0.70 + $data['score']['proximity_score'] * 0.30, 1);
        $this->assertEquals($expected, $data['score']['value']);
    }

    public function test_returns_404_for_coordinates_outside_sweden(): void
    {
        $response = $this->getJson('/api/location/48.8566,2.3522');

        $response->assertNotFound()
            ->assertJson(['error' => 'Location outside Sweden']);
    }

    public function test_returns_nearby_schools(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 65.0,
            'computed_at' => now(),
        ]);

        School::factory()->create([
            'name' => 'Vasaskolan',
            'type_of_schooling' => 'Grundskola',
            'operator_type' => 'KOMMUN',
            'status' => 'active',
            'lat' => 59.335,
            'lng' => 18.061,
            'deso_code' => '0180C1090',
        ]);

        DB::statement('
            UPDATE schools SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326)
            WHERE lat IS NOT NULL AND lng IS NOT NULL AND geom IS NULL
        ');

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/location/59.335,18.06');

        $response->assertOk();
        $schools = $response->json('schools');
        $this->assertCount(1, $schools);
        $this->assertEquals('Vasaskolan', $schools[0]['name']);
        $this->assertArrayHasKey('distance_m', $schools[0]);
    }

    public function test_returns_indicators_for_location(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 55.0,
            'computed_at' => now(),
        ]);

        $indicator = Indicator::create([
            'slug' => 'employment_rate',
            'name' => 'Sysselsättningsgrad',
            'unit' => '%',
            'direction' => 'positive',
            'weight' => 0.08,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'source' => 'scb',
            'is_active' => true,
            'display_order' => 2,
        ]);

        IndicatorValue::create([
            'deso_code' => '0180C1090',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 72.3,
            'normalized_value' => 0.61,
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/location/59.335,18.06');

        $response->assertOk();
        $indicators = $response->json('indicators');
        $this->assertCount(1, $indicators);
        $this->assertEquals('employment_rate', $indicators[0]['slug']);
        $this->assertEquals(72.3, $indicators[0]['raw_value']);
    }

    public function test_score_labels_are_correct(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        // Blended = area * 0.70 + proximity * 0.30
        // With no POIs/schools, proximity ≈ 0 (negative POI gives 100, but weighted low)
        // So we need area scores high enough that blended still crosses thresholds.
        // Labels: <20 Hög risk, 20-39 Förhöjd risk, 40-59 Blandat, 60-79 Stabilt, 80+ Starkt
        $cases = [
            [5.0, 'Hög risk'],         // blended ≈ 5*0.70 + ~5*0.30 ≈ 5
            [35.0, 'Förhöjd risk'],     // blended ≈ 35*0.70 + ~5*0.30 ≈ 26
            [65.0, 'Blandat'],          // blended ≈ 65*0.70 + ~5*0.30 ≈ 47
            [90.0, 'Stabilt / Positivt'], // blended ≈ 90*0.70 + ~5*0.30 ≈ 65
        ];

        foreach ($cases as [$score, $expectedLabel]) {
            CompositeScore::where('deso_code', '0180C1090')->delete();
            CompositeScore::create([
                'deso_code' => '0180C1090',
                'year' => 2024,
                'score' => $score,
                'computed_at' => now(),
            ]);

            $response = $this->getJson('/api/location/59.335,18.06');
            $response->assertJsonPath('score.label', $expectedLabel);
        }
    }

    public function test_returns_score_with_null_area_when_no_composite_score_exists(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        $response = $this->getJson('/api/location/59.335,18.06');

        $response->assertOk()
            ->assertJsonPath('score.area_score', null)
            ->assertJsonPath('location.deso_code', '0180C1090');

        // Score should still have a value (blended with default area of 50)
        $data = $response->json();
        $this->assertNotNull($data['score']['value']);
        $this->assertNotNull($data['score']['proximity_score']);
    }

    public function test_excludes_inactive_indicators(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 50.0,
            'computed_at' => now(),
        ]);

        $activeIndicator = Indicator::create([
            'slug' => 'median_income',
            'name' => 'Medianinkomst',
            'unit' => 'SEK',
            'direction' => 'positive',
            'weight' => 0.09,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'source' => 'scb',
            'is_active' => true,
            'display_order' => 1,
        ]);

        $inactiveIndicator = Indicator::create([
            'slug' => 'old_metric',
            'name' => 'Old Metric',
            'unit' => '%',
            'direction' => 'positive',
            'weight' => 0.0,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'source' => 'scb',
            'is_active' => false,
            'display_order' => 99,
        ]);

        IndicatorValue::create([
            'deso_code' => '0180C1090',
            'indicator_id' => $activeIndicator->id,
            'year' => 2024,
            'raw_value' => 287000,
            'normalized_value' => 0.78,
        ]);

        IndicatorValue::create([
            'deso_code' => '0180C1090',
            'indicator_id' => $inactiveIndicator->id,
            'year' => 2024,
            'raw_value' => 50.0,
            'normalized_value' => 0.50,
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/location/59.335,18.06');

        $response->assertOk();
        $indicators = $response->json('indicators');
        $this->assertCount(1, $indicators);
        $this->assertEquals('median_income', $indicators[0]['slug']);
    }

    public function test_public_tier_returns_preview_with_categories(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 65.0,
            'computed_at' => now(),
        ]);

        $indicator = Indicator::create([
            'slug' => 'median_income',
            'name' => 'Medianinkomst',
            'unit' => 'SEK',
            'direction' => 'positive',
            'weight' => 0.09,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'source' => 'scb',
            'is_active' => true,
            'is_free_preview' => true,
            'display_order' => 1,
        ]);

        $indicator2 = Indicator::create([
            'slug' => 'crime_violent_rate',
            'name' => 'Våldsbrott',
            'unit' => '/100k',
            'direction' => 'negative',
            'weight' => 0.08,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'source' => 'bra',
            'is_active' => true,
            'is_free_preview' => true,
            'display_order' => 2,
        ]);

        // Non-free indicator in economy category
        $indicator3 = Indicator::create([
            'slug' => 'low_economic_standard_pct',
            'name' => 'Låg ekonomisk standard',
            'unit' => '%',
            'direction' => 'negative',
            'weight' => 0.05,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'source' => 'scb',
            'is_active' => true,
            'is_free_preview' => false,
            'display_order' => 3,
        ]);

        IndicatorValue::create([
            'deso_code' => '0180C1090',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 287000,
            'normalized_value' => 0.78,
        ]);

        IndicatorValue::create([
            'deso_code' => '0180C1090',
            'indicator_id' => $indicator2->id,
            'year' => 2024,
            'raw_value' => 15.2,
            'normalized_value' => 0.45,
        ]);

        IndicatorValue::create([
            'deso_code' => '0180C1090',
            'indicator_id' => $indicator3->id,
            'year' => 2024,
            'raw_value' => 12.5,
            'normalized_value' => 0.35,
        ]);

        // Unauthenticated request → Public tier
        $response = $this->getJson('/api/location/59.335,18.06');

        $response->assertOk()
            ->assertJsonPath('tier', 0)
            ->assertJsonPath('indicators', [])
            ->assertJsonPath('schools', [])
            ->assertJsonPath('pois', [])
            ->assertJsonPath('poi_categories', [])
            ->assertJsonPath('proximity', null)
            ->assertJsonPath('score.area_score', 65)
            ->assertJsonPath('location.deso_code', '0180C1090')
            ->assertJsonStructure([
                'preview' => [
                    'data_point_count',
                    'source_count',
                    'sources',
                    'categories',
                    'nearby_school_count',
                    'cta_summary' => ['indicator_count', 'insight_count', 'poi_count'],
                ],
            ])
            ->assertJsonPath('preview.data_point_count', 3)
            ->assertJsonPath('preview.nearby_school_count', 0);

        // Verify categories structure
        $categories = $response->json('preview.categories');
        $this->assertCount(5, $categories);

        $categorySlugs = array_column($categories, 'slug');
        $this->assertEquals(['safety', 'economy', 'education', 'environment', 'proximity'], $categorySlugs);

        // Each category must have required fields
        foreach ($categories as $cat) {
            $this->assertArrayHasKey('slug', $cat);
            $this->assertArrayHasKey('label', $cat);
            $this->assertArrayHasKey('icon', $cat);
            $this->assertArrayHasKey('stat_line', $cat);
            $this->assertArrayHasKey('has_data', $cat);
            $this->assertArrayHasKey('free_indicators', $cat);
            $this->assertArrayHasKey('locked_count', $cat);
            $this->assertArrayHasKey('indicator_count', $cat);
        }

        // Safety category: crime_violent_rate is free preview
        $safety = collect($categories)->firstWhere('slug', 'safety');
        $this->assertTrue($safety['has_data']);
        $this->assertCount(1, $safety['free_indicators']);
        $this->assertEquals('crime_violent_rate', $safety['free_indicators'][0]['slug']);
        $this->assertEquals(15.2, $safety['free_indicators'][0]['raw_value']);
        $this->assertEquals(45, $safety['free_indicators'][0]['percentile']);
        $this->assertEquals('negative', $safety['free_indicators'][0]['direction']);

        // Economy category: median_income is free, low_economic_standard_pct is locked
        $economy = collect($categories)->firstWhere('slug', 'economy');
        $this->assertTrue($economy['has_data']);
        $this->assertCount(1, $economy['free_indicators']);
        $this->assertEquals('median_income', $economy['free_indicators'][0]['slug']);
        $this->assertEquals(287000.0, $economy['free_indicators'][0]['raw_value']);
        $this->assertEquals(78, $economy['free_indicators'][0]['percentile']);
        $this->assertEquals(1, $economy['locked_count']); // low_economic_standard_pct

        // Sources should include both scb and bra
        $sources = $response->json('preview.sources');
        $this->assertContains('scb', $sources);
        $this->assertContains('bra', $sources);

        // CTA summary should have real counts
        $ctaSummary = $response->json('preview.cta_summary');
        $this->assertGreaterThanOrEqual(3, $ctaSummary['indicator_count']);
    }

    public function test_public_tier_excludes_free_indicators_without_data(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 65.0,
            'computed_at' => now(),
        ]);

        // Free preview indicator with NO data for this DeSO
        Indicator::create([
            'slug' => 'perceived_safety',
            'name' => 'Upplevd trygghet',
            'unit' => 'index',
            'direction' => 'positive',
            'weight' => 0.07,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'source' => 'bra',
            'is_active' => true,
            'is_free_preview' => true,
            'display_order' => 1,
        ]);

        // Non-free indicator WITH data
        $indicator2 = Indicator::create([
            'slug' => 'crime_total_rate',
            'name' => 'Total brottslighet',
            'unit' => '/100k',
            'direction' => 'negative',
            'weight' => 0.04,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'source' => 'bra',
            'is_active' => true,
            'is_free_preview' => false,
            'display_order' => 2,
        ]);

        IndicatorValue::create([
            'deso_code' => '0180C1090',
            'indicator_id' => $indicator2->id,
            'year' => 2024,
            'raw_value' => 120.0,
            'normalized_value' => 0.60,
        ]);

        $response = $this->getJson('/api/location/59.335,18.06');

        $response->assertOk();

        // Safety category should have data but no free indicators (perceived_safety has no value)
        $safety = collect($response->json('preview.categories'))->firstWhere('slug', 'safety');
        $this->assertTrue($safety['has_data']);
        $this->assertCount(0, $safety['free_indicators']);
        $this->assertEquals(1, $safety['locked_count']); // crime_total_rate
    }

    public function test_public_tier_free_indicator_direction_and_percentile(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 65.0,
            'computed_at' => now(),
        ]);

        // Positive direction indicator
        $indicator = Indicator::create([
            'slug' => 'employment_rate',
            'name' => 'Sysselsättningsgrad',
            'unit' => '%',
            'direction' => 'positive',
            'weight' => 0.08,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'source' => 'scb',
            'is_active' => true,
            'is_free_preview' => true,
            'display_order' => 1,
        ]);

        IndicatorValue::create([
            'deso_code' => '0180C1090',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 72.3,
            'normalized_value' => 0.614,
        ]);

        $response = $this->getJson('/api/location/59.335,18.06');

        $response->assertOk();

        $economy = collect($response->json('preview.categories'))->firstWhere('slug', 'economy');
        $freeIndicators = $economy['free_indicators'];
        $this->assertCount(1, $freeIndicators);
        $this->assertEquals('employment_rate', $freeIndicators[0]['slug']);
        $this->assertEquals(72.3, $freeIndicators[0]['raw_value']);
        $this->assertEquals(61, $freeIndicators[0]['percentile']); // round(0.614 * 100)
        $this->assertEquals('positive', $freeIndicators[0]['direction']);
        $this->assertEquals('%', $freeIndicators[0]['unit']);
    }

    public function test_public_tier_preview_includes_nearby_schools(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 65.0,
            'computed_at' => now(),
        ]);

        School::factory()->create([
            'name' => 'Vasaskolan',
            'type_of_schooling' => 'Grundskola',
            'operator_type' => 'KOMMUN',
            'status' => 'active',
            'lat' => 59.335,
            'lng' => 18.061,
            'deso_code' => '0180C1090',
        ]);

        DB::statement('
            UPDATE schools SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326)
            WHERE lat IS NOT NULL AND lng IS NOT NULL AND geom IS NULL
        ');

        // Unauthenticated request → Public tier
        $response = $this->getJson('/api/location/59.335,18.06');

        $response->assertOk()
            ->assertJsonPath('tier', 0)
            ->assertJsonPath('preview.nearby_school_count', 1)
            // School names should NOT be in the response
            ->assertJsonPath('schools', []);
    }

    public function test_admin_tier_returns_pois_within_radius(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 70.0,
            'computed_at' => now(),
        ]);

        PoiCategory::create([
            'slug' => 'grocery',
            'name' => 'Grocery',
            'osm_tags' => ['shop' => ['supermarket']],
            'catchment_km' => 1.0,
            'is_active' => true,
            'show_on_map' => true,
            'color' => '#16a34a',
            'icon' => 'shopping-cart',
            'signal' => 'positive',
        ]);

        // POI within 3km of test point
        Poi::factory()->grocery()->create([
            'name' => 'ICA Nära',
            'lat' => 59.336,
            'lng' => 18.062,
        ]);

        DB::statement('
            UPDATE pois SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326)
            WHERE lat IS NOT NULL AND lng IS NOT NULL AND geom IS NULL
        ');

        // POI far away (should not be included)
        Poi::factory()->grocery()->create([
            'name' => 'Coop Far Away',
            'lat' => 59.5,
            'lng' => 18.5,
        ]);

        DB::statement('
            UPDATE pois SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326)
            WHERE lat IS NOT NULL AND lng IS NOT NULL AND geom IS NULL
        ');

        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->getJson('/api/location/59.335,18.06');

        $response->assertOk()
            ->assertJsonPath('tier', 99);

        $pois = $response->json('pois');
        $this->assertCount(1, $pois);
        $this->assertEquals('ICA Nära', $pois[0]['name']);
        $this->assertEquals('grocery', $pois[0]['category']);
        $this->assertArrayHasKey('distance_m', $pois[0]);

        // Check poi_categories metadata is included
        $categories = $response->json('poi_categories');
        $this->assertArrayHasKey('grocery', $categories);
        $this->assertEquals('#16a34a', $categories['grocery']['color']);
    }

    public function test_authenticated_user_gets_full_data(): void
    {
        $this->createDesoWithGeom('0180C1090', 'Stockholm');

        CompositeScore::create([
            'deso_code' => '0180C1090',
            'year' => 2024,
            'score' => 55.0,
            'computed_at' => now(),
        ]);

        $indicator = Indicator::create([
            'slug' => 'employment_rate',
            'name' => 'Sysselsättningsgrad',
            'unit' => '%',
            'direction' => 'positive',
            'weight' => 0.08,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'source' => 'scb',
            'is_active' => true,
            'display_order' => 2,
        ]);

        IndicatorValue::create([
            'deso_code' => '0180C1090',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 72.3,
            'normalized_value' => 0.61,
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/location/59.335,18.06');

        $response->assertOk()
            ->assertJsonPath('tier', 1);

        $indicators = $response->json('indicators');
        $this->assertCount(1, $indicators);
        $this->assertEquals('employment_rate', $indicators[0]['slug']);
    }

    public function test_tile_route_returns_transparent_png_for_missing_tile(): void
    {
        $response = $this->get('/tiles/2024/5/0/0.png');

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_explore_route_renders_map_page(): void
    {
        $response = $this->get('/explore/59.3340,18.0650');

        $response->assertOk();
    }
}
