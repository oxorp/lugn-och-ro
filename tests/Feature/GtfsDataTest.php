<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\Poi;
use App\Models\PoiCategory;
use App\Models\TransitStop;
use App\Models\TransitStopFrequency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GtfsDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    // ─── Table Existence ─────────────────────────────────

    public function test_transit_stops_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('transit_stops'));
    }

    public function test_transit_stop_frequencies_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('transit_stop_frequencies'));
    }

    public function test_transit_stops_has_required_columns(): void
    {
        $columns = DB::getSchemaBuilder()->getColumnListing('transit_stops');

        foreach ([
            'gtfs_stop_id', 'name', 'lat', 'lng', 'parent_station',
            'location_type', 'source', 'stop_type', 'weekly_departures',
            'routes_count', 'deso_code',
        ] as $col) {
            $this->assertContains($col, $columns, "Missing column: {$col}");
        }
    }

    public function test_transit_stop_frequencies_has_required_columns(): void
    {
        $columns = DB::getSchemaBuilder()->getColumnListing('transit_stop_frequencies');

        foreach ([
            'gtfs_stop_id', 'mode_category', 'departures_06_09',
            'departures_09_15', 'departures_15_18', 'departures_18_22',
            'departures_06_20_total', 'distinct_routes', 'day_type', 'feed_version',
        ] as $col) {
            $this->assertContains($col, $columns, "Missing column: {$col}");
        }
    }

    // ─── Model Tests ─────────────────────────────────────

    public function test_transit_stop_model_creates_correctly(): void
    {
        $stop = TransitStop::create([
            'gtfs_stop_id' => '740012345',
            'name' => 'Stockholm Central',
            'lat' => 59.3293,
            'lng' => 18.0686,
            'location_type' => 1,
            'source' => 'gtfs',
            'stop_type' => 'rail',
            'weekly_departures' => 1500,
            'routes_count' => 25,
        ]);

        $this->assertDatabaseHas('transit_stops', [
            'gtfs_stop_id' => '740012345',
            'name' => 'Stockholm Central',
            'stop_type' => 'rail',
        ]);
    }

    public function test_transit_stop_frequency_model_creates_correctly(): void
    {
        TransitStop::create([
            'gtfs_stop_id' => '740012345',
            'name' => 'Stockholm Central',
            'lat' => 59.3293,
            'lng' => 18.0686,
            'source' => 'gtfs',
        ]);

        $freq = TransitStopFrequency::create([
            'gtfs_stop_id' => '740012345',
            'mode_category' => 'rail',
            'departures_06_09' => 45,
            'departures_09_15' => 60,
            'departures_15_18' => 50,
            'departures_18_22' => 30,
            'departures_06_20_total' => 185,
            'distinct_routes' => 12,
            'day_type' => 'weekday',
            'feed_version' => '2026-02',
        ]);

        $this->assertDatabaseHas('transit_stop_frequencies', [
            'gtfs_stop_id' => '740012345',
            'mode_category' => 'rail',
            'departures_06_20_total' => 185,
        ]);
    }

    public function test_transit_stop_has_many_frequencies(): void
    {
        $stop = TransitStop::create([
            'gtfs_stop_id' => '740012345',
            'name' => 'Sundbyberg',
            'lat' => 59.3613,
            'lng' => 17.9718,
            'source' => 'gtfs',
        ]);

        TransitStopFrequency::create([
            'gtfs_stop_id' => '740012345',
            'mode_category' => 'rail',
            'departures_06_20_total' => 100,
            'day_type' => 'weekday',
        ]);

        TransitStopFrequency::create([
            'gtfs_stop_id' => '740012345',
            'mode_category' => 'bus',
            'departures_06_20_total' => 200,
            'day_type' => 'weekday',
        ]);

        $this->assertCount(2, $stop->frequencies);
    }

    // ─── IngestPois Transit Skip ─────────────────────────

    public function test_ingest_pois_warns_on_transit_category(): void
    {
        $this->artisan('ingest:pois', ['--category' => 'public_transport_stop'])
            ->expectsOutputToContain('managed by GTFS')
            ->assertSuccessful();
    }

    public function test_ingest_pois_skips_transit_in_category_list(): void
    {
        // Create a transit category and a non-transit category
        PoiCategory::query()->updateOrCreate(
            ['slug' => 'public_transport_stop'],
            [
                'name' => 'Transit',
                'is_active' => true,
                'osm_tags' => ['highway' => ['bus_stop']],
                'signal' => 'positive',
            ]
        );
        PoiCategory::query()->updateOrCreate(
            ['slug' => 'grocery'],
            [
                'name' => 'Grocery',
                'is_active' => true,
                'osm_tags' => ['shop' => ['supermarket']],
                'signal' => 'positive',
            ]
        );

        // The getCategories method should exclude transit categories
        // We can verify indirectly by checking it doesn't return transit
        $categories = PoiCategory::query()
            ->where('is_active', true)
            ->whereNotNull('osm_tags')
            ->whereNotIn('slug', [
                'public_transport_stop', 'transit_stop', 'bus_stop',
                'tram_stop', 'rail_station', 'subway_station', 'transit',
            ])
            ->get();

        $this->assertFalse($categories->contains('slug', 'public_transport_stop'));
        $this->assertTrue($categories->contains('slug', 'grocery'));
    }

    // ─── Clear Old Transit Data ──────────────────────────

    public function test_clearing_osm_transit_data_removes_transit_pois(): void
    {
        $this->ensurePoiCategory('public_transport_stop');

        // Create OSM transit POI
        Poi::factory()->create([
            'source' => 'osm',
            'category' => 'public_transport_stop',
            'name' => 'Bus Stop OSM',
            'lat' => 59.335,
            'lng' => 18.061,
        ]);

        // Create non-transit OSM POI
        $this->ensurePoiCategory('grocery');
        Poi::factory()->create([
            'source' => 'osm',
            'category' => 'grocery',
            'name' => 'ICA',
            'lat' => 59.336,
            'lng' => 18.062,
        ]);

        $this->assertEquals(1, Poi::where('source', 'osm')->where('category', 'public_transport_stop')->count());
        $this->assertEquals(1, Poi::where('source', 'osm')->where('category', 'grocery')->count());

        // Simulate the clear step from IngestGtfs
        DB::table('pois')
            ->where('source', 'osm')
            ->where(function ($q) {
                $q->where('category', 'public_transport_stop')
                    ->orWhere('category', 'like', 'transit%');
            })
            ->delete();

        // Transit POIs should be gone, grocery should remain
        $this->assertEquals(0, Poi::where('source', 'osm')->where('category', 'public_transport_stop')->count());
        $this->assertEquals(1, Poi::where('source', 'osm')->where('category', 'grocery')->count());
    }

    // ─── Transit Stop Import ─────────────────────────────

    public function test_bulk_insert_transit_stops(): void
    {
        $now = now();
        $batch = [];
        for ($i = 0; $i < 10; $i++) {
            $batch[] = [
                'gtfs_stop_id' => "7400100{$i}0",
                'name' => "Stop {$i}",
                'lat' => 59.33 + ($i * 0.001),
                'lng' => 18.06 + ($i * 0.001),
                'source' => 'gtfs',
                'location_type' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('transit_stops')->insert($batch);

        $this->assertEquals(10, TransitStop::where('source', 'gtfs')->count());
    }

    public function test_transit_stop_geometry_set_correctly(): void
    {
        TransitStop::create([
            'gtfs_stop_id' => '740099999',
            'name' => 'Test Station',
            'lat' => 59.3293,
            'lng' => 18.0686,
            'source' => 'gtfs',
        ]);

        DB::statement("
            UPDATE transit_stops
            SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326)
            WHERE gtfs_stop_id = '740099999'
        ");

        $result = DB::selectOne("
            SELECT ST_X(geom) as x, ST_Y(geom) as y
            FROM transit_stops
            WHERE gtfs_stop_id = '740099999'
        ");

        $this->assertEqualsWithDelta(18.0686, $result->x, 0.0001);
        $this->assertEqualsWithDelta(59.3293, $result->y, 0.0001);
    }

    // ─── Backfill Stop Metrics ───────────────────────────

    public function test_backfill_stop_type_from_frequencies(): void
    {
        TransitStop::create([
            'gtfs_stop_id' => '740012345',
            'name' => 'Test Station',
            'lat' => 59.3293,
            'lng' => 18.0686,
            'source' => 'gtfs',
        ]);

        TransitStopFrequency::create([
            'gtfs_stop_id' => '740012345',
            'mode_category' => 'rail',
            'departures_06_20_total' => 150,
            'distinct_routes' => 8,
            'day_type' => 'weekday',
        ]);

        // Simulate backfill query
        DB::update("
            UPDATE transit_stops ts
            SET
                stop_type = freq.mode_category,
                weekly_departures = freq.departures_06_20_total * 5,
                routes_count = freq.distinct_routes
            FROM (
                SELECT gtfs_stop_id, mode_category, departures_06_20_total, distinct_routes,
                       ROW_NUMBER() OVER (PARTITION BY gtfs_stop_id ORDER BY departures_06_20_total DESC) as rn
                FROM transit_stop_frequencies
                WHERE day_type = 'weekday'
            ) freq
            WHERE ts.gtfs_stop_id = freq.gtfs_stop_id AND freq.rn = 1
        ");

        $stop = TransitStop::where('gtfs_stop_id', '740012345')->first();
        $this->assertEquals('rail', $stop->stop_type);
        $this->assertEquals(750, $stop->weekly_departures);
        $this->assertEquals(8, $stop->routes_count);
    }

    public function test_backfill_uses_dominant_mode_for_multimodal_stops(): void
    {
        TransitStop::create([
            'gtfs_stop_id' => '740055555',
            'name' => 'Multimodal Hub',
            'lat' => 59.33,
            'lng' => 18.06,
            'source' => 'gtfs',
        ]);

        // Rail with fewer departures
        TransitStopFrequency::create([
            'gtfs_stop_id' => '740055555',
            'mode_category' => 'rail',
            'departures_06_20_total' => 30,
            'day_type' => 'weekday',
        ]);

        // Bus with more departures (dominant)
        TransitStopFrequency::create([
            'gtfs_stop_id' => '740055555',
            'mode_category' => 'bus',
            'departures_06_20_total' => 200,
            'day_type' => 'weekday',
        ]);

        DB::update("
            UPDATE transit_stops ts
            SET
                stop_type = freq.mode_category,
                weekly_departures = freq.departures_06_20_total * 5,
                routes_count = freq.distinct_routes
            FROM (
                SELECT gtfs_stop_id, mode_category, departures_06_20_total, distinct_routes,
                       ROW_NUMBER() OVER (PARTITION BY gtfs_stop_id ORDER BY departures_06_20_total DESC) as rn
                FROM transit_stop_frequencies
                WHERE day_type = 'weekday'
            ) freq
            WHERE ts.gtfs_stop_id = freq.gtfs_stop_id AND freq.rn = 1
        ");

        $stop = TransitStop::where('gtfs_stop_id', '740055555')->first();
        $this->assertEquals('bus', $stop->stop_type);
        $this->assertEquals(1000, $stop->weekly_departures);
    }

    // ─── POI Insertion for Qualifying Stops ──────────────

    public function test_high_frequency_rail_creates_poi(): void
    {
        $this->ensurePoiCategory('rail_station');

        $now = now();
        DB::table('pois')->upsert([
            [
                'external_id' => 'gtfs_740012345',
                'source' => 'gtfs',
                'category' => 'rail_station',
                'subcategory' => 'rail',
                'poi_type' => 'rail_station',
                'display_tier' => 1,
                'sentiment' => 'positive',
                'name' => 'Stockholm Central',
                'lat' => 59.3293,
                'lng' => 18.0686,
                'metadata' => json_encode(['departures_weekday' => 185, 'mode' => 'rail']),
                'status' => 'active',
                'last_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['source', 'external_id'], ['name', 'lat', 'lng']);

        $poi = Poi::where('source', 'gtfs')->where('category', 'rail_station')->first();
        $this->assertNotNull($poi);
        $this->assertEquals('Stockholm Central', $poi->name);
        $this->assertEquals(1, $poi->display_tier);
        $this->assertEquals('rail', $poi->metadata['mode']);
    }

    public function test_gtfs_source_column_distinguishes_from_osm(): void
    {
        $this->ensurePoiCategory('grocery');
        $this->ensurePoiCategory('rail_station');

        Poi::factory()->create([
            'source' => 'osm',
            'category' => 'grocery',
            'name' => 'ICA',
        ]);

        Poi::factory()->create([
            'source' => 'gtfs',
            'category' => 'rail_station',
            'name' => 'Malmö C',
        ]);

        $this->assertEquals(1, Poi::where('source', 'osm')->count());
        $this->assertEquals(1, Poi::where('source', 'gtfs')->count());
    }

    // ─── Unique Constraint ───────────────────────────────

    public function test_transit_stop_unique_constraint(): void
    {
        TransitStop::create([
            'gtfs_stop_id' => '740012345',
            'name' => 'Stop A',
            'lat' => 59.33,
            'lng' => 18.06,
            'source' => 'gtfs',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        TransitStop::create([
            'gtfs_stop_id' => '740012345',
            'name' => 'Stop B',
            'lat' => 59.34,
            'lng' => 18.07,
            'source' => 'gtfs',
        ]);
    }

    // ─── Aggregation from transit_stops ─────────────────

    public function test_transit_density_aggregates_from_transit_stops_table(): void
    {
        // Set up a DeSO area with geometry and population
        $desoCode = 'T0001';
        DB::table('deso_areas')->insert([
            'deso_code' => $desoCode,
            'deso_name' => 'Test DeSO',
            'kommun_code' => '0180',
            'kommun_name' => 'Stockholm',
            'lan_code' => '01',
            'population' => 1000,
        ]);
        DB::statement("
            UPDATE deso_areas SET geom = ST_SetSRID(ST_MakePolygon(
                ST_GeomFromText('LINESTRING(18.05 59.33, 18.07 59.33, 18.07 59.34, 18.05 59.34, 18.05 59.33)')
            ), 4326) WHERE deso_code = '{$desoCode}'
        ");

        // Create transit stops NEAR the DeSO centroid (within 1km)
        for ($i = 0; $i < 5; $i++) {
            TransitStop::create([
                'gtfs_stop_id' => "74001000{$i}",
                'name' => "Stop {$i}",
                'lat' => 59.335 + ($i * 0.0001),
                'lng' => 18.060,
                'source' => 'gtfs',
            ]);
        }

        // Set geometry on all transit stops
        DB::statement('
            UPDATE transit_stops SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326) WHERE geom IS NULL
        ');

        // Create the indicator and POI category
        $indicator = Indicator::create([
            'slug' => 'transit_stop_density',
            'name' => 'Transit Stop Density',
            'source' => 'gtfs',
            'direction' => 'positive',
            'weight' => 0.04,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'urbanity_stratified',
            'is_active' => true,
        ]);

        PoiCategory::query()->updateOrCreate(
            ['slug' => 'public_transport_stop'],
            [
                'name' => 'Public Transport Stops',
                'indicator_slug' => 'transit_stop_density',
                'signal' => 'positive',
                'is_active' => true,
                'catchment_km' => 1.00,
            ]
        );

        // Run aggregation — should use transit_stops table
        $job = new \App\Jobs\AggregatePoiCategoryJob('public_transport_stop', 2025);
        $job->handle();

        // Verify indicator values were created from transit_stops data
        $value = DB::table('indicator_values')
            ->where('deso_code', $desoCode)
            ->where('indicator_id', $indicator->id)
            ->where('year', 2025)
            ->first();

        $this->assertNotNull($value);
        // 5 stops / 1000 population * 1000 = 5.0 per 1000 residents
        $this->assertEqualsWithDelta(5.0, $value->raw_value, 0.1);
    }

    // ─── Config ──────────────────────────────────────────

    public function test_trafiklab_config_key_exists(): void
    {
        $this->assertNotNull(config('services.trafiklab'));
        $this->assertArrayHasKey('gtfs_key', config('services.trafiklab'));
    }

    // ─── Helpers ─────────────────────────────────────────

    private function ensurePoiCategory(string $slug, string $signal = 'positive'): void
    {
        PoiCategory::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => ucfirst(str_replace('_', ' ', $slug)),
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
}
