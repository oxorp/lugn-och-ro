<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Models\Poi;
use App\Models\PoiCategory;
use App\Models\User;
use App\Services\OverpassService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PoiDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        // Clear POI category caches to prevent stale data between tests
        cache()->forget('poi-categories-display');
        cache()->forget('poi-visible-category-slugs');
    }

    private function ensurePoiCategory(string $slug, string $signal = 'positive'): void
    {
        PoiCategory::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => ucfirst($slug),
                'signal' => $signal,
                'is_active' => true,
                'show_on_map' => true,
                'display_tier' => 3,
                'icon' => 'circle',
                'color' => '#666666',
                'category_group' => 'test',
            ]
        );
    }

    // --- Table existence tests ---

    public function test_pois_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('pois'));
    }

    public function test_poi_categories_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('poi_categories'));
    }

    // --- Model tests ---

    public function test_poi_model_can_be_created(): void
    {
        $poi = Poi::query()->create([
            'external_id' => 'osm_node_12345',
            'source' => 'osm',
            'category' => 'grocery',
            'name' => 'ICA Kvantum',
            'lat' => 59.3293,
            'lng' => 18.0686,
            'status' => 'active',
            'last_verified_at' => now(),
        ]);

        $this->assertDatabaseHas('pois', [
            'external_id' => 'osm_node_12345',
            'category' => 'grocery',
            'name' => 'ICA Kvantum',
        ]);
    }

    public function test_poi_unique_constraint_on_source_and_external_id(): void
    {
        Poi::query()->create([
            'external_id' => 'osm_node_12345',
            'source' => 'osm',
            'category' => 'grocery',
            'lat' => 59.3293,
            'lng' => 18.0686,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Poi::query()->create([
            'external_id' => 'osm_node_12345',
            'source' => 'osm',
            'category' => 'grocery',
            'lat' => 59.4000,
            'lng' => 18.1000,
        ]);
    }

    public function test_poi_tags_cast_to_array(): void
    {
        $poi = Poi::query()->create([
            'external_id' => 'osm_node_99999',
            'source' => 'osm',
            'category' => 'grocery',
            'lat' => 59.3293,
            'lng' => 18.0686,
            'tags' => ['shop' => 'supermarket', 'name' => 'ICA'],
        ]);

        $poi->refresh();

        $this->assertIsArray($poi->tags);
        $this->assertEquals('supermarket', $poi->tags['shop']);
    }

    public function test_poi_category_model_can_be_created(): void
    {
        $cat = PoiCategory::query()->create([
            'slug' => 'test_category',
            'name' => 'Test Category',
            'signal' => 'positive',
            'osm_tags' => ['shop' => ['supermarket']],
            'catchment_km' => 2.0,
        ]);

        $this->assertDatabaseHas('poi_categories', [
            'slug' => 'test_category',
            'signal' => 'positive',
        ]);
        $this->assertIsArray($cat->osm_tags);
    }

    public function test_poi_category_unique_slug(): void
    {
        PoiCategory::query()->create([
            'slug' => 'unique_test',
            'name' => 'First',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        PoiCategory::query()->create([
            'slug' => 'unique_test',
            'name' => 'Second',
        ]);
    }

    // --- Seeder tests ---

    public function test_poi_category_seeder_creates_categories(): void
    {
        $this->artisan('db:seed', ['--class' => 'PoiCategorySeeder']);

        $this->assertDatabaseHas('poi_categories', ['slug' => 'grocery', 'signal' => 'positive']);
        $this->assertDatabaseHas('poi_categories', ['slug' => 'healthcare', 'signal' => 'positive']);
        $this->assertDatabaseHas('poi_categories', ['slug' => 'gambling', 'signal' => 'negative']);
        $this->assertDatabaseHas('poi_categories', ['slug' => 'public_transport_stop', 'signal' => 'positive']);

        // New categories from POI expansion
        $this->assertDatabaseHas('poi_categories', ['slug' => 'wastewater_plant', 'signal' => 'negative']);
        $this->assertDatabaseHas('poi_categories', ['slug' => 'landfill', 'signal' => 'negative']);
        $this->assertDatabaseHas('poi_categories', ['slug' => 'prison', 'signal' => 'negative']);
        $this->assertDatabaseHas('poi_categories', ['slug' => 'library', 'signal' => 'positive']);
        $this->assertDatabaseHas('poi_categories', ['slug' => 'park', 'signal' => 'positive']);
        $this->assertDatabaseHas('poi_categories', ['slug' => 'nature_reserve', 'signal' => 'positive']);

        $this->assertEquals(31, PoiCategory::query()->count());
        $this->assertEquals(29, PoiCategory::query()->where('is_active', true)->count());
    }

    public function test_poi_indicator_seeder_creates_indicators(): void
    {
        $this->artisan('db:seed', ['--class' => 'PoiIndicatorSeeder']);

        $this->assertDatabaseHas('indicators', [
            'slug' => 'grocery_density',
            'category' => 'amenities',
            'normalization_scope' => 'urbanity_stratified',
        ]);
        $this->assertDatabaseHas('indicators', [
            'slug' => 'transit_stop_density',
            'category' => 'transport',
            'normalization_scope' => 'urbanity_stratified',
        ]);
        $this->assertDatabaseHas('indicators', [
            'slug' => 'gambling_density',
            'direction' => 'negative',
        ]);

        $this->assertEquals(8, Indicator::query()->where('source', 'osm')->count());
    }

    public function test_poi_indicator_seeder_rebalances_weights(): void
    {
        // Create some existing indicators first
        Indicator::query()->create(['slug' => 'median_income', 'name' => 'Median Income', 'source' => 'scb', 'unit' => 'sek', 'direction' => 'positive', 'weight' => 0.12, 'normalization' => 'rank_percentile', 'is_active' => true, 'category' => 'income']);
        Indicator::query()->create(['slug' => 'debt_rate_pct', 'name' => 'Debt Rate', 'source' => 'kronofogden', 'unit' => 'percent', 'direction' => 'negative', 'weight' => 0.06, 'normalization' => 'rank_percentile', 'is_active' => true, 'category' => 'financial_distress']);

        $this->artisan('db:seed', ['--class' => 'PoiIndicatorSeeder']);

        $this->assertDatabaseHas('indicators', ['slug' => 'median_income', 'weight' => '0.0650']);
        $this->assertDatabaseHas('indicators', ['slug' => 'debt_rate_pct', 'weight' => '0.0500']);
        $this->assertDatabaseHas('indicators', ['slug' => 'grocery_density', 'weight' => '0.0400']);
    }

    // --- OverpassService tests ---

    public function test_overpass_service_builds_tag_filters(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    [
                        'type' => 'node',
                        'id' => 12345,
                        'lat' => 59.3293,
                        'lon' => 18.0686,
                        'tags' => ['shop' => 'supermarket', 'name' => 'ICA Maxi'],
                    ],
                    [
                        'type' => 'way',
                        'id' => 67890,
                        'center' => ['lat' => 59.3500, 'lon' => 18.1000],
                        'tags' => ['shop' => 'convenience', 'name' => 'Pressbyrån'],
                    ],
                ],
            ]),
        ]);

        $service = new OverpassService;
        $results = $service->querySweden(['shop' => ['supermarket', 'convenience']]);

        $this->assertCount(2, $results);
        $this->assertEquals(59.3293, $results[0]['lat']);
        $this->assertEquals(18.0686, $results[0]['lng']);
        $this->assertEquals(12345, $results[0]['osm_id']);
        $this->assertEquals('node', $results[0]['osm_type']);
        $this->assertEquals('ICA Maxi', $results[0]['name']);
    }

    public function test_overpass_service_filters_elements_without_coordinates(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    [
                        'type' => 'node',
                        'id' => 111,
                        'lat' => 59.0,
                        'lon' => 18.0,
                        'tags' => [],
                    ],
                    [
                        'type' => 'relation',
                        'id' => 222,
                        'tags' => [],
                    ],
                ],
            ]),
        ]);

        $service = new OverpassService;
        $results = $service->querySweden(['shop' => ['supermarket']]);

        $this->assertCount(1, $results);
    }

    public function test_overpass_service_throws_on_failure(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::response('Error', 500),
        ]);

        $this->expectException(\RuntimeException::class);

        $service = new OverpassService;
        $service->querySweden(['shop' => ['supermarket']]);
    }

    // --- DeSO assignment tests ---

    public function test_assign_poi_deso_command(): void
    {
        $this->insertDesoWithGeometry('0180C6250', '0180', '01', 'Stockholm');

        // Create a POI inside the DeSO polygon (18.0-18.1, 59.3-59.4)
        Poi::query()->create([
            'external_id' => 'osm_node_1',
            'source' => 'osm',
            'category' => 'grocery',
            'lat' => 59.35,
            'lng' => 18.05,
        ]);

        // Set geometry
        DB::statement('UPDATE pois SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326) WHERE id = (SELECT MAX(id) FROM pois)');

        $this->artisan('assign:poi-deso')
            ->assertSuccessful();

        $this->assertDatabaseHas('pois', [
            'external_id' => 'osm_node_1',
            'deso_code' => '0180C6250',
        ]);
    }

    public function test_assign_poi_deso_leaves_outside_pois_unassigned(): void
    {
        $this->insertDesoWithGeometry('0180C6250', '0180', '01', 'Stockholm');

        // Create a POI outside the DeSO polygon
        Poi::query()->create([
            'external_id' => 'osm_node_outside',
            'source' => 'osm',
            'category' => 'grocery',
            'lat' => 65.0,
            'lng' => 20.0,
        ]);

        DB::statement('UPDATE pois SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326) WHERE id = (SELECT MAX(id) FROM pois)');

        $this->artisan('assign:poi-deso')
            ->assertSuccessful();

        $this->assertDatabaseHas('pois', [
            'external_id' => 'osm_node_outside',
            'deso_code' => null,
        ]);
    }

    // --- Aggregation tests ---

    public function test_aggregate_poi_indicators_command(): void
    {
        $this->insertDesoWithGeometry('0180C6250', '0180', '01', 'Stockholm', 1500);

        $this->artisan('db:seed', ['--class' => 'PoiCategorySeeder']);
        $this->artisan('db:seed', ['--class' => 'PoiIndicatorSeeder']);

        // Create grocery POIs inside the DeSO (within 1.5km catchment)
        for ($i = 0; $i < 3; $i++) {
            $poi = Poi::query()->create([
                'external_id' => "osm_node_grocery_{$i}",
                'source' => 'osm',
                'category' => 'grocery',
                'lat' => 59.35 + ($i * 0.001),
                'lng' => 18.05,
                'status' => 'active',
            ]);

            DB::statement('UPDATE pois SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326) WHERE id = ?', [$poi->id]);
        }

        $this->artisan('aggregate:poi-indicators', ['--year' => 2025, '--sync' => true])
            ->assertSuccessful();

        $indicator = Indicator::query()->where('slug', 'grocery_density')->first();
        $this->assertNotNull($indicator);

        $value = IndicatorValue::query()
            ->where('deso_code', '0180C6250')
            ->where('indicator_id', $indicator->id)
            ->where('year', 2025)
            ->first();

        $this->assertNotNull($value);
        // 3 POIs / 1500 population * 1000 = 2.0 per 1,000
        $this->assertEquals(2.0, round((float) $value->raw_value, 1));
    }

    public function test_aggregate_poi_indicators_stores_zero_for_no_pois(): void
    {
        $this->insertDesoWithGeometry('1480C1200', '1480', '14', 'Göteborg', 2000);

        $this->artisan('db:seed', ['--class' => 'PoiCategorySeeder']);
        $this->artisan('db:seed', ['--class' => 'PoiIndicatorSeeder']);

        // No POIs created — should get raw_value = 0
        $this->artisan('aggregate:poi-indicators', ['--year' => 2025, '--sync' => true])
            ->assertSuccessful();

        $indicator = Indicator::query()->where('slug', 'grocery_density')->first();

        $value = IndicatorValue::query()
            ->where('deso_code', '1480C1200')
            ->where('indicator_id', $indicator->id)
            ->where('year', 2025)
            ->first();

        $this->assertNotNull($value);
        $this->assertEquals(0.0, (float) $value->raw_value);
    }

    public function test_aggregate_poi_indicators_category_filter(): void
    {
        $this->insertDesoWithGeometry('0180C6250', '0180', '01', 'Stockholm', 1500);

        $this->artisan('db:seed', ['--class' => 'PoiCategorySeeder']);
        $this->artisan('db:seed', ['--class' => 'PoiIndicatorSeeder']);

        $poi = Poi::query()->create([
            'external_id' => 'osm_node_filter_test',
            'source' => 'osm',
            'category' => 'grocery',
            'lat' => 59.35,
            'lng' => 18.05,
            'status' => 'active',
        ]);

        DB::statement('UPDATE pois SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326) WHERE id = ?', [$poi->id]);

        // Only aggregate grocery, not all categories
        $this->artisan('aggregate:poi-indicators', ['--year' => 2025, '--category' => 'grocery', '--sync' => true])
            ->assertSuccessful();

        $groceryIndicator = Indicator::query()->where('slug', 'grocery_density')->first();
        $this->assertNotNull(
            IndicatorValue::query()
                ->where('indicator_id', $groceryIndicator->id)
                ->where('year', 2025)
                ->first()
        );

        // Healthcare should NOT have been aggregated
        $healthcareIndicator = Indicator::query()->where('slug', 'healthcare_density')->first();
        $this->assertNull(
            IndicatorValue::query()
                ->where('indicator_id', $healthcareIndicator->id)
                ->where('year', 2025)
                ->first()
        );
    }

    public function test_aggregate_poi_indicators_dispatches_batch(): void
    {
        $this->artisan('db:seed', ['--class' => 'PoiCategorySeeder']);
        $this->artisan('db:seed', ['--class' => 'PoiIndicatorSeeder']);

        Bus::fake();

        $this->artisan('aggregate:poi-indicators', ['--year' => 2025])
            ->assertSuccessful();

        Bus::assertBatched(fn (\Illuminate\Bus\PendingBatch $batch) => $batch->name === 'POI Aggregation 2025'
            && $batch->jobs->count() === 8
        );
    }

    // --- API endpoint tests ---

    public function test_pois_api_endpoint_returns_404_for_unknown_deso(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->getJson('/api/deso/UNKNOWN/pois');

        $response->assertNotFound();
    }

    public function test_pois_api_endpoint_returns_grouped_data(): void
    {
        $this->insertDesoWithGeometry('0180C6250', '0180', '01', 'Stockholm');

        // Create POIs inside the DeSO
        foreach (['ICA Maxi', 'Coop'] as $name) {
            $poi = Poi::query()->create([
                'external_id' => 'osm_node_'.str_replace(' ', '', $name),
                'source' => 'osm',
                'category' => 'grocery',
                'name' => $name,
                'lat' => 59.35,
                'lng' => 18.05,
                'deso_code' => '0180C6250',
                'status' => 'active',
            ]);

            DB::statement('UPDATE pois SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326) WHERE id = ?', [$poi->id]);
        }

        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->getJson('/api/deso/0180C6250/pois');

        $response->assertOk();
        $response->assertJsonStructure([
            'deso_code',
            'categories' => [
                '*' => ['category', 'count', 'within_deso', 'items'],
            ],
        ]);
        $response->assertJsonPath('deso_code', '0180C6250');
    }

    // --- Factory tests ---

    public function test_poi_factory_creates_valid_poi(): void
    {
        $poi = Poi::factory()->grocery()->create();

        $this->assertDatabaseHas('pois', [
            'id' => $poi->id,
            'category' => 'grocery',
            'source' => 'osm',
            'status' => 'active',
        ]);
    }

    // --- Viewport API tests ---

    public function test_viewport_api_returns_pois_within_bbox(): void
    {
        $this->ensurePoiCategory('grocery');

        $poi = Poi::query()->create([
            'external_id' => 'osm_node_viewport_1',
            'source' => 'osm',
            'category' => 'grocery',
            'poi_type' => 'grocery',
            'display_tier' => 4,
            'sentiment' => 'positive',
            'lat' => 59.35,
            'lng' => 18.05,
            'status' => 'active',
        ]);
        DB::statement('UPDATE pois SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326) WHERE id = ?', [$poi->id]);

        $response = $this->getJson('/api/pois?bbox=18.0,59.3,18.1,59.4&zoom=14');

        $response->assertOk();
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('grocery', $data[0]['poi_type']);
        $this->assertEquals('positive', $data[0]['sentiment']);
    }

    public function test_viewport_api_filters_by_zoom_tier(): void
    {
        $this->ensurePoiCategory('grocery');

        // Tier 4 POI should not appear at zoom 10 (max tier 2)
        $poi = Poi::query()->create([
            'external_id' => 'osm_node_tier_test',
            'source' => 'osm',
            'category' => 'grocery',
            'poi_type' => 'grocery',
            'display_tier' => 4,
            'sentiment' => 'positive',
            'lat' => 59.35,
            'lng' => 18.05,
            'status' => 'active',
        ]);
        DB::statement('UPDATE pois SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326) WHERE id = ?', [$poi->id]);

        // Zoom 10 → max tier 2, so tier 4 POI should not appear
        $response = $this->getJson('/api/pois?bbox=18.0,59.3,18.1,59.4&zoom=10');
        $response->assertOk();
        $this->assertCount(0, $response->json());

        // Zoom 14 → max tier 4, so tier 4 POI should appear
        $response = $this->getJson('/api/pois?bbox=18.0,59.3,18.1,59.4&zoom=14');
        $response->assertOk();
        $this->assertCount(1, $response->json());
    }

    public function test_viewport_api_filters_by_category(): void
    {
        $this->ensurePoiCategory('grocery');
        $this->ensurePoiCategory('healthcare');

        foreach (['grocery', 'healthcare'] as $cat) {
            $poi = Poi::query()->create([
                'external_id' => "osm_node_cat_{$cat}",
                'source' => 'osm',
                'category' => $cat,
                'poi_type' => $cat,
                'display_tier' => 2,
                'sentiment' => 'positive',
                'lat' => 59.35,
                'lng' => 18.05,
                'status' => 'active',
            ]);
            DB::statement('UPDATE pois SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326) WHERE id = ?', [$poi->id]);
        }

        // Filter to only grocery
        $response = $this->getJson('/api/pois?bbox=18.0,59.3,18.1,59.4&zoom=14&categories=grocery');
        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertEquals('grocery', $response->json()[0]['category']);
    }

    public function test_viewport_api_excludes_pois_outside_bbox(): void
    {
        // POI outside bbox
        $poi = Poi::query()->create([
            'external_id' => 'osm_node_outside_bbox',
            'source' => 'osm',
            'category' => 'grocery',
            'poi_type' => 'grocery',
            'display_tier' => 2,
            'sentiment' => 'positive',
            'lat' => 55.0,
            'lng' => 13.0,
            'status' => 'active',
        ]);
        DB::statement('UPDATE pois SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326) WHERE id = ?', [$poi->id]);

        // Stockholm bbox
        $response = $this->getJson('/api/pois?bbox=18.0,59.3,18.1,59.4&zoom=14');
        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    public function test_viewport_api_returns_no_pois_below_zoom_8(): void
    {
        $poi = Poi::query()->create([
            'external_id' => 'osm_node_low_zoom',
            'source' => 'osm',
            'category' => 'prison',
            'poi_type' => 'prison',
            'display_tier' => 1,
            'sentiment' => 'negative',
            'lat' => 59.35,
            'lng' => 18.05,
            'status' => 'active',
        ]);
        DB::statement('UPDATE pois SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326) WHERE id = ?', [$poi->id]);

        $response = $this->getJson('/api/pois?bbox=18.0,59.3,18.1,59.4&zoom=7');
        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    public function test_poi_categories_api_returns_active_categories(): void
    {
        $this->artisan('db:seed', ['--class' => 'PoiCategorySeeder']);

        $response = $this->getJson('/api/pois/categories');
        $response->assertOk();

        $data = $response->json();
        $this->assertGreaterThanOrEqual(29, count($data));
        $this->assertArrayHasKey('slug', $data[0]);
        $this->assertArrayHasKey('display_tier', $data[0]);
        $this->assertArrayHasKey('icon', $data[0]);
        $this->assertArrayHasKey('color', $data[0]);
    }

    public function test_viewport_api_has_cache_control_header(): void
    {
        $response = $this->getJson('/api/pois?bbox=18.0,59.3,18.1,59.4&zoom=14');
        $response->assertOk();
        $response->assertHeader('Cache-Control');
    }

    // --- Compound tag filter tests ---

    public function test_overpass_service_builds_compound_tag_filters(): void
    {
        $service = new OverpassService;
        $reflection = new \ReflectionMethod($service, 'buildTagFilters');

        // Simple tags (existing behavior)
        $result = $reflection->invoke($service, ['shop' => ['supermarket', 'convenience']]);
        $this->assertStringContainsString('nwr["shop"~"supermarket|convenience"](area.sweden);', $result);

        // Compound tags with _and key
        $result = $reflection->invoke($service, [
            '_and' => [[['power', 'generator'], ['generator:source', 'wind']]],
            'man_made' => ['windmill'],
        ]);
        $this->assertStringContainsString('nwr["power"="generator"]["generator:source"="wind"](area.sweden);', $result);
        $this->assertStringContainsString('nwr["man_made"~"windmill"](area.sweden);', $result);
    }

    private function insertDesoWithGeometry(string $desoCode, string $kommunCode, string $lanCode, string $kommunName, ?int $population = null): void
    {
        DB::statement("
            INSERT INTO deso_areas (deso_code, kommun_code, kommun_name, lan_code, population, geom, area_km2, created_at, updated_at)
            VALUES (
                :deso_code, :kommun_code, :kommun_name, :lan_code, :population,
                ST_Multi(ST_SetSRID(ST_GeomFromText('POLYGON((18.0 59.3, 18.1 59.3, 18.1 59.4, 18.0 59.4, 18.0 59.3))'), 4326)),
                1.5, NOW(), NOW()
            )
        ", [
            'deso_code' => $desoCode,
            'kommun_code' => $kommunCode,
            'kommun_name' => $kommunName,
            'lan_code' => $lanCode,
            'population' => $population,
        ]);
    }
}
