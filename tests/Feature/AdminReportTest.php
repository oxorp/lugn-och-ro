<?php

namespace Tests\Feature;

use App\Models\CompositeScore;
use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        // Fake external HTTP calls (Photon geocoding)
        Http::fake([
            'photon.komoot.io/*' => Http::response([
                'features' => [[
                    'properties' => [
                        'street' => 'Testgatan',
                        'housenumber' => '1',
                        'city' => 'Stockholm',
                    ],
                ]],
            ]),
        ]);
    }

    private function createDesoWithGeom(string $desoCode = '0180C1090'): void
    {
        DB::table('deso_areas')->insert([
            'deso_code' => $desoCode,
            'deso_name' => 'Testområde',
            'kommun_code' => '0180',
            'kommun_name' => 'Stockholm',
            'lan_code' => '01',
            'lan_name' => 'Stockholms län',
            'urbanity_tier' => 'urban',
            'area_km2' => 0.5,
            'population' => 2000,
            'geom' => DB::raw("ST_SetSRID(ST_GeomFromText('POLYGON((18.05 59.33, 18.07 59.33, 18.07 59.34, 18.05 59.34, 18.05 59.33))'), 4326)"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedIndicatorData(string $desoCode = '0180C1090'): void
    {
        CompositeScore::create([
            'deso_code' => $desoCode,
            'year' => 2024,
            'score' => 72.5,
            'trend_1y' => 3.2,
            'factor_scores' => ['median_income' => 0.85],
            'top_positive' => ['median_income'],
            'top_negative' => [],
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
            'category' => 'economy',
            'is_active' => true,
            'display_order' => 1,
        ]);

        IndicatorValue::create([
            'deso_code' => $desoCode,
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 287000,
            'normalized_value' => 0.78,
        ]);
    }

    public function test_admin_can_generate_report(): void
    {
        $this->createDesoWithGeom();
        $this->seedIndicatorData();

        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post(route('admin.reports.generate'), [
            'lat' => 59.335,
            'lng' => 18.06,
        ]);

        $response->assertRedirect();

        $report = Report::latest()->first();
        $this->assertNotNull($report);
        $this->assertEquals('completed', $report->status);
        $this->assertEquals(0, $report->amount_ore);
        $this->assertEquals($admin->id, $report->user_id);
        $this->assertNotNull($report->area_indicators);
        $this->assertNotEmpty($report->area_indicators);
        $this->assertEquals(1, $report->indicator_count);
    }

    public function test_admin_report_has_snapshot_data(): void
    {
        $this->createDesoWithGeom();
        $this->seedIndicatorData();

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.reports.generate'), [
            'lat' => 59.335,
            'lng' => 18.06,
        ]);

        $report = Report::latest()->first();

        // Snapshot fields are populated
        $this->assertNotNull($report->deso_meta);
        $this->assertEquals('0180C1090', $report->deso_meta['deso_code']);
        $this->assertNotNull($report->category_verdicts);
        $this->assertArrayHasKey('economy', $report->category_verdicts);
        $this->assertNotNull($report->score_history);
        $this->assertEquals(2024, $report->year);
        $this->assertNotNull($report->outlook);
    }

    public function test_non_admin_cannot_generate_report(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->post(route('admin.reports.generate'), [
            'lat' => 59.335,
            'lng' => 18.06,
        ]);

        $response->assertStatus(403);
        $this->assertEquals(0, Report::count());
    }

    public function test_guest_cannot_generate_report(): void
    {
        $response = $this->post(route('admin.reports.generate'), [
            'lat' => 59.335,
            'lng' => 18.06,
        ]);

        $response->assertRedirect(); // Redirected to login
        $this->assertEquals(0, Report::count());
    }

    public function test_invalid_coordinates_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post(route('admin.reports.generate'), [
            'lat' => 10.0, // Outside Sweden
            'lng' => 18.06,
        ]);

        $response->assertSessionHasErrors('lat');
    }

    public function test_report_generated_without_deso_data(): void
    {
        // No DeSO seeded — location falls outside any known area
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post(route('admin.reports.generate'), [
            'lat' => 67.0,
            'lng' => 20.0,
        ]);

        $response->assertRedirect();

        $report = Report::latest()->first();
        $this->assertNotNull($report);
        $this->assertEquals('completed', $report->status);
        $this->assertNull($report->deso_code);
    }

    public function test_report_address_from_reverse_geocoding(): void
    {
        $this->createDesoWithGeom();
        $this->seedIndicatorData();

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.reports.generate'), [
            'lat' => 59.335,
            'lng' => 18.06,
        ]);

        $report = Report::latest()->first();
        $this->assertEquals('Testgatan, 1, Stockholm', $report->address);
    }
}
