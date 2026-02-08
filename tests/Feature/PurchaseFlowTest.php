<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    private function createDesoWithGeom(string $desoCode = '0180C1010', string $kommunName = 'Stockholm'): void
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

    // -- Purchase page --

    public function test_purchase_page_loads_for_valid_coordinates(): void
    {
        $this->createDesoWithGeom();

        $response = $this->get('/purchase/59.335,18.06');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('purchase/flow')
            ->has('lat')
            ->has('lng')
            ->where('kommun_name', 'Stockholm')
        );
    }

    public function test_purchase_page_returns404_for_out_of_bounds_coordinates(): void
    {
        $response = $this->get('/purchase/40.0,18.0');

        $response->assertStatus(404);
    }

    public function test_purchase_page_shows_score_when_available(): void
    {
        $this->createDesoWithGeom();

        DB::table('composite_scores')->insert([
            'deso_code' => '0180C1010',
            'year' => 2025,
            'score' => 72.5,
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/purchase/59.335,18.06');

        $response->assertInertia(fn ($page) => $page
            ->where('score', '72.50')
        );
    }

    // -- Dev bypass checkout --

    public function test_dev_bypass_creates_completed_report_without_stripe(): void
    {
        config(['stripe.secret' => null]);
        app()->detectEnvironment(fn () => 'local');

        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/checkout', [
                'lat' => 59.33,
                'lng' => 18.06,
                'email' => 'guest@example.com',
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['checkout_url', 'dev_mode']);

        $this->assertDatabaseHas('reports', [
            'guest_email' => 'guest@example.com',
            'status' => 'completed',
            'amount_ore' => 7900,
        ]);
    }

    public function test_dev_bypass_for_authenticated_user(): void
    {
        config(['stripe.secret' => null]);
        app()->detectEnvironment(fn () => 'local');

        $user = User::factory()->create();

        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->actingAs($user)->postJson('/purchase/checkout', [
                'lat' => 59.33,
                'lng' => 18.06,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('reports', [
            'user_id' => $user->id,
            'guest_email' => null,
            'status' => 'completed',
        ]);
    }

    // -- Checkout validation --

    public function test_checkout_requires_email_for_guest(): void
    {
        config(['stripe.secret' => null]);
        app()->detectEnvironment(fn () => 'local');

        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/checkout', [
                'lat' => 59.33,
                'lng' => 18.06,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_checkout_validates_coordinate_bounds(): void
    {
        config(['stripe.secret' => null]);
        app()->detectEnvironment(fn () => 'local');

        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/checkout', [
                'lat' => 40.0,
                'lng' => 18.06,
                'email' => 'test@example.com',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('lat');
    }

    // -- Report display --

    public function test_report_show_renders_for_completed_report(): void
    {
        $report = Report::factory()->completed()->create();

        $response = $this->get("/reports/{$report->uuid}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/show')
            ->where('report.uuid', (string) $report->uuid)
        );
    }

    public function test_report_show_increments_view_count(): void
    {
        $report = Report::factory()->completed()->create(['view_count' => 5]);

        $this->get("/reports/{$report->uuid}");

        $this->assertDatabaseHas('reports', [
            'id' => $report->id,
            'view_count' => 6,
        ]);
    }

    public function test_report_show_returns404_for_pending_report(): void
    {
        $report = Report::factory()->create(['status' => 'pending']);

        $response = $this->get("/reports/{$report->uuid}");

        $response->assertStatus(404);
    }

    public function test_report_show_returns404_for_expired_report(): void
    {
        $report = Report::factory()->expired()->create();

        $response = $this->get("/reports/{$report->uuid}");

        $response->assertStatus(404);
    }

    // -- Cancel --

    public function test_cancel_expires_report_and_redirects(): void
    {
        $report = Report::factory()->create([
            'status' => 'pending',
            'lat' => 59.33,
            'lng' => 18.06,
        ]);

        $response = $this->get('/purchase/cancel?session_id='.$report->stripe_session_id);

        $response->assertRedirect("/explore/{$report->lat},{$report->lng}");

        $this->assertDatabaseHas('reports', [
            'id' => $report->id,
            'status' => 'expired',
        ]);
    }

    public function test_cancel_without_session_id_redirects_home(): void
    {
        $response = $this->get('/purchase/cancel');

        $response->assertRedirect('/');
    }

    // -- Success --

    public function test_success_redirects_to_report_when_completed(): void
    {
        $report = Report::factory()->completed()->create();

        $response = $this->get('/purchase/success?session_id='.$report->stripe_session_id);

        $response->assertRedirect('/reports/'.(string) $report->uuid);
    }

    public function test_success_shows_processing_when_pending(): void
    {
        $report = Report::factory()->create(['status' => 'pending']);

        $response = $this->get('/purchase/success?session_id='.$report->stripe_session_id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('purchase/processing')
            ->where('session_id', $report->stripe_session_id)
            ->where('report_uuid', (string) $report->uuid)
        );
    }

    public function test_success_without_session_id_redirects_home(): void
    {
        $response = $this->get('/purchase/success');

        $response->assertRedirect('/');
    }

    // -- Status polling --

    public function test_status_returns_report_status(): void
    {
        $report = Report::factory()->completed()->create();

        $response = $this->getJson('/purchase/status/'.$report->stripe_session_id);

        $response->assertOk();
        $response->assertJson([
            'status' => 'completed',
            'report_uuid' => (string) $report->uuid,
        ]);
    }

    public function test_status_returns404_for_unknown_session(): void
    {
        $response = $this->getJson('/purchase/status/cs_nonexistent');

        $response->assertStatus(404);
        $response->assertJson(['status' => 'unknown']);
    }

    // -- Cleanup command --

    public function test_cleanup_expires_old_pending_reports(): void
    {
        $old = Report::factory()->create([
            'status' => 'pending',
            'created_at' => now()->subHours(3),
        ]);

        $recent = Report::factory()->create([
            'status' => 'pending',
            'created_at' => now()->subMinutes(30),
        ]);

        $completed = Report::factory()->completed()->create([
            'created_at' => now()->subHours(3),
        ]);

        $this->artisan('purchase:cleanup')
            ->expectsOutputToContain('Expired 1 abandoned checkouts');

        $this->assertDatabaseHas('reports', ['id' => $old->id, 'status' => 'expired']);
        $this->assertDatabaseHas('reports', ['id' => $recent->id, 'status' => 'pending']);
        $this->assertDatabaseHas('reports', ['id' => $completed->id, 'status' => 'completed']);
    }
}
