<?php

namespace Tests\Feature;

use App\Models\Indicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminIndicatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_indicators_page_loads(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $response = $this->get(route('admin.indicators'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/indicators')
            ->has('indicators', 8)
        );
    }

    public function test_admin_can_update_indicator_weight(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $indicator = Indicator::query()->where('slug', 'median_income')->first();

        $response = $this->put(route('admin.indicators.update', $indicator), [
            'direction' => 'positive',
            'weight' => 0.25,
            'normalization' => 'rank_percentile',
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

        $response = $this->put(route('admin.indicators.update', $indicator), [
            'direction' => 'negative',
            'weight' => 0.05,
            'normalization' => 'rank_percentile',
            'is_active' => true,
        ]);

        $response->assertRedirect();

        $indicator->refresh();
        $this->assertEquals('negative', $indicator->direction);
        $this->assertEquals(0.05, (float) $indicator->weight);
    }

    public function test_admin_update_validates_direction(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $indicator = Indicator::query()->where('slug', 'median_income')->first();

        $response = $this->put(route('admin.indicators.update', $indicator), [
            'direction' => 'invalid',
            'weight' => 0.15,
            'normalization' => 'rank_percentile',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('direction');
    }

    public function test_admin_update_validates_weight_range(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $indicator = Indicator::query()->where('slug', 'median_income')->first();

        $response = $this->put(route('admin.indicators.update', $indicator), [
            'direction' => 'positive',
            'weight' => 1.5,
            'normalization' => 'rank_percentile',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('weight');
    }

    public function test_scores_api_returns_json(): void
    {
        $response = $this->get('/api/deso/scores?year=2024');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
    }
}
