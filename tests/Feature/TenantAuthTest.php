<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Models\Tenant;
use App\Models\TenantIndicatorWeight;
use App\Models\User;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_creation_generates_uuid(): void
    {
        $tenant = Tenant::create(['name' => 'Test Org', 'slug' => 'test-org']);

        $this->assertNotNull($tenant->uuid);
        $this->assertEquals(36, strlen($tenant->uuid));
    }

    public function test_tenant_initialize_weights_copies_indicator_defaults(): void
    {
        $indicator = Indicator::create([
            'slug' => 'test_ind',
            'name' => 'Test Indicator',
            'source' => 'test',
            'direction' => 'positive',
            'weight' => 0.15,
            'is_active' => true,
        ]);

        $totalIndicators = Indicator::count();

        $tenant = Tenant::create(['slug' => 'test-tenant']);
        $tenant->initializeWeights();

        $this->assertEquals($totalIndicators, $tenant->indicatorWeights()->count());

        $weight = $tenant->indicatorWeights()
            ->where('indicator_id', $indicator->id)
            ->first();
        $this->assertNotNull($weight);
        $this->assertEquals(0.15, (float) $weight->weight);
        $this->assertEquals('positive', $weight->direction);
        $this->assertTrue($weight->is_active);
    }

    public function test_user_belongs_to_tenant(): void
    {
        $tenant = Tenant::create(['slug' => 'test-tenant']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertNotNull($user->tenant);
        $this->assertEquals($tenant->id, $user->tenant->id);
    }

    public function test_tenant_has_many_users(): void
    {
        $tenant = Tenant::create(['slug' => 'test-tenant']);
        User::factory()->count(3)->create(['tenant_id' => $tenant->id]);

        $this->assertEquals(3, $tenant->users()->count());
    }

    public function test_resolve_tenant_middleware_binds_tenant(): void
    {
        $tenant = Tenant::create(['slug' => 'test-tenant']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'is_admin' => true,
        ]);

        Indicator::create([
            'slug' => 'test_resolve',
            'name' => 'Test Resolve',
            'source' => 'test',
            'direction' => 'positive',
            'weight' => 0.10,
            'is_active' => true,
        ]);

        // Hit an authenticated route that goes through ResolveTenant middleware
        $response = $this->actingAs($user)->get(route('admin.indicators'));

        $response->assertOk();
    }

    public function test_admin_route_requires_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get(route('admin.indicators'));

        $response->assertForbidden();
    }

    public function test_admin_route_requires_authentication(): void
    {
        $response = $this->get(route('admin.indicators'));

        $response->assertRedirect(route('login'));
    }

    public function test_login_works_and_redirects_to_map(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('map', absolute: false));
    }

    public function test_scoring_service_uses_tenant_weights(): void
    {
        $tenant = Tenant::create(['slug' => 'test-tenant']);

        $indicator = Indicator::create([
            'slug' => 'test_score',
            'name' => 'Test Score',
            'source' => 'test',
            'direction' => 'positive',
            'weight' => 0.50,
            'is_active' => true,
        ]);

        // Tenant overrides weight to 1.0 and direction to negative
        TenantIndicatorWeight::create([
            'tenant_id' => $tenant->id,
            'indicator_id' => $indicator->id,
            'weight' => 1.0,
            'direction' => 'negative',
            'is_active' => true,
        ]);

        IndicatorValue::create([
            'deso_code' => '0180C0001',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 100,
            'normalized_value' => 0.80,
        ]);

        $service = new ScoringService;

        // Public scoring: positive direction, 0.80 * 100 = 80
        $service->computeScores(2024);
        $publicScore = \App\Models\CompositeScore::query()
            ->where('deso_code', '0180C0001')
            ->where('year', 2024)
            ->first();
        $this->assertEquals(80.0, (float) $publicScore->score);

        // Tenant scoring: negative direction, (1 - 0.80) * 100 = 20
        $service->computeScores(2024, $tenant);
        $tenantScore = \App\Models\CompositeScore::query()
            ->whereHas('scoreVersion', fn ($q) => $q->where('tenant_id', $tenant->id))
            ->where('deso_code', '0180C0001')
            ->where('year', 2024)
            ->first();
        $this->assertEquals(20.0, (float) $tenantScore->score);
    }

    public function test_admin_indicator_update_writes_tenant_weight(): void
    {
        $tenant = Tenant::create(['slug' => 'test-tenant']);
        $admin = User::factory()->create([
            'is_admin' => true,
            'tenant_id' => $tenant->id,
        ]);

        $indicator = Indicator::create([
            'slug' => 'test_admin_w',
            'name' => 'Test Admin Weight',
            'source' => 'test',
            'direction' => 'positive',
            'weight' => 0.10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->put(route('admin.indicators.update', $indicator), [
            'direction' => 'negative',
            'weight' => 0.30,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'is_active' => true,
        ]);

        $response->assertRedirect();

        // Check tenant weight was created
        $tenantWeight = TenantIndicatorWeight::query()
            ->where('tenant_id', $tenant->id)
            ->where('indicator_id', $indicator->id)
            ->first();

        $this->assertNotNull($tenantWeight);
        $this->assertEquals('negative', $tenantWeight->direction);
        $this->assertEquals(0.30, (float) $tenantWeight->weight);
    }

    public function test_seeder_creates_tenant_and_admin(): void
    {
        $this->seed(\Database\Seeders\TenantAndAdminSeeder::class);

        $tenant = Tenant::where('slug', 'default')->first();
        $this->assertNotNull($tenant);

        $admin = User::where('email', 'admin@example.com')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->is_admin);
        $this->assertEquals($tenant->id, $admin->tenant_id);
    }

    public function test_current_tenant_helper_returns_null_when_not_bound(): void
    {
        $this->assertNull(currentTenant());
    }

    public function test_current_tenant_helper_returns_tenant_when_bound(): void
    {
        $tenant = Tenant::create(['slug' => 'test-helper']);
        app()->instance('currentTenant', $tenant);

        $this->assertEquals($tenant->id, currentTenant()->id);
    }

    public function test_handle_inertia_requests_shares_tenant(): void
    {
        $tenant = Tenant::create(['slug' => 'test-inertia']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get(route('map'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('tenant')
            ->where('tenant.uuid', $tenant->uuid)
        );
    }

    public function test_handle_inertia_requests_shares_is_admin(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($user)->get(route('map'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('auth.user')
            ->where('auth.user.is_admin', true)
        );
    }
}
