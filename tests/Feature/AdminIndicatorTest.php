<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminIndicatorTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_admin_indicators_page_loads(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $response = $this->actingAs($this->createAdmin())->get(route('admin.indicators'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/indicators')
            ->has('indicators', 11)
        );
    }

    public function test_admin_can_update_indicator_weight(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $indicator = Indicator::query()->where('slug', 'median_income')->first();

        $response = $this->actingAs($this->createAdmin())->put(route('admin.indicators.update', $indicator), [
            'direction' => 'positive',
            'weight' => 0.25,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'is_active' => true,
        ]);

        $response->assertRedirect();

        $indicator->refresh();
        $this->assertEquals(0.25, (float) $indicator->weight);
    }

    public function test_admin_can_update_indicator_direction(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $indicator = Indicator::query()->where('slug', 'rental_tenure_pct')->first();

        $response = $this->actingAs($this->createAdmin())->put(route('admin.indicators.update', $indicator), [
            'direction' => 'negative',
            'weight' => 0.05,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'is_active' => true,
        ]);

        $response->assertRedirect();

        $indicator->refresh();
        $this->assertEquals('negative', $indicator->direction);
        $this->assertEquals(0.05, (float) $indicator->weight);
    }

    public function test_admin_can_update_normalization_scope(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $indicator = Indicator::query()->where('slug', 'median_income')->first();

        $response = $this->actingAs($this->createAdmin())->put(route('admin.indicators.update', $indicator), [
            'direction' => 'positive',
            'weight' => 0.09,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'urbanity_stratified',
            'is_active' => true,
        ]);

        $response->assertRedirect();

        $indicator->refresh();
        $this->assertEquals('urbanity_stratified', $indicator->normalization_scope);
    }

    public function test_admin_update_validates_normalization_scope(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $indicator = Indicator::query()->where('slug', 'median_income')->first();

        $response = $this->actingAs($this->createAdmin())->put(route('admin.indicators.update', $indicator), [
            'direction' => 'positive',
            'weight' => 0.09,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'invalid_scope',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('normalization_scope');
    }

    public function test_admin_update_validates_direction(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $indicator = Indicator::query()->where('slug', 'median_income')->first();

        $response = $this->actingAs($this->createAdmin())->put(route('admin.indicators.update', $indicator), [
            'direction' => 'invalid',
            'weight' => 0.15,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('direction');
    }

    public function test_admin_update_validates_weight_range(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $indicator = Indicator::query()->where('slug', 'median_income')->first();

        $response = $this->actingAs($this->createAdmin())->put(route('admin.indicators.update', $indicator), [
            'direction' => 'positive',
            'weight' => 1.5,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('weight');
    }

    public function test_admin_indicators_page_includes_urbanity_distribution(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $response = $this->actingAs($this->createAdmin())->get(route('admin.indicators'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/indicators')
            ->has('urbanityDistribution')
        );
    }

    public function test_non_admin_cannot_access_admin_routes(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get(route('admin.indicators'));

        $response->assertForbidden();
    }

    public function test_guest_cannot_access_admin_routes(): void
    {
        $response = $this->get(route('admin.indicators'));

        $response->assertRedirect();
    }

    public function test_scores_api_returns_json(): void
    {
        $response = $this->get('/api/deso/scores?year=2024');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
    }
}
