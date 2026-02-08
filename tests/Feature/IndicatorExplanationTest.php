<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndicatorExplanationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\IndicatorSeeder::class);
        $this->seed(\Database\Seeders\CrimeIndicatorSeeder::class);
        $this->seed(\Database\Seeders\KronofogdenIndicatorSeeder::class);
        $this->seed(\Database\Seeders\PoiIndicatorSeeder::class);
    }

    private function actingAsAdmin(): static
    {
        return $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_explanation_seeder_populates_all_indicators(): void
    {
        $this->seed(\Database\Seeders\IndicatorExplanationSeeder::class);

        $indicators = Indicator::query()->get();

        foreach ($indicators as $indicator) {
            $this->assertNotNull(
                $indicator->description_short,
                "description_short missing for {$indicator->slug}"
            );
            $this->assertNotNull(
                $indicator->description_long,
                "description_long missing for {$indicator->slug}"
            );
            $this->assertNotNull(
                $indicator->source_name,
                "source_name missing for {$indicator->slug}"
            );
        }
    }

    public function test_explanation_fields_are_fillable(): void
    {
        $indicator = Indicator::query()->first();

        $indicator->update([
            'description_short' => 'Test short desc',
            'description_long' => 'Test long description',
            'methodology_note' => 'Test methodology',
            'national_context' => 'National avg: 50%',
            'source_name' => 'Test Source',
            'source_url' => 'https://example.com',
            'update_frequency' => 'Annually',
            'data_vintage' => '2024',
        ]);

        $indicator->refresh();

        $this->assertEquals('Test short desc', $indicator->description_short);
        $this->assertEquals('Test long description', $indicator->description_long);
        $this->assertEquals('Test methodology', $indicator->methodology_note);
        $this->assertEquals('National avg: 50%', $indicator->national_context);
        $this->assertEquals('Test Source', $indicator->source_name);
        $this->assertEquals('https://example.com', $indicator->source_url);
        $this->assertEquals('Annually', $indicator->update_frequency);
        $this->assertEquals('2024', $indicator->data_vintage);
    }

    public function test_map_page_includes_indicator_meta(): void
    {
        $this->seed(\Database\Seeders\IndicatorExplanationSeeder::class);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('explore/map-page')
            ->has('indicatorMeta')
            ->has('indicatorMeta.median_income.description_short')
            ->has('indicatorMeta.median_income.source_name')
            ->has('indicatorMeta.median_income.national_context')
        );
    }

    public function test_admin_can_update_explanation_fields(): void
    {
        $this->seed(\Database\Seeders\IndicatorExplanationSeeder::class);

        $indicator = Indicator::query()->where('slug', 'median_income')->first();

        $response = $this->actingAsAdmin()->put(route('admin.indicators.update', $indicator), [
            'direction' => 'positive',
            'weight' => 0.065,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'is_active' => true,
            'description_short' => 'Updated short desc',
            'description_long' => 'Updated long description',
            'national_context' => 'Updated context',
            'source_name' => 'Updated SCB',
            'source_url' => 'https://updated.scb.se',
            'update_frequency' => 'Monthly',
        ]);

        $response->assertRedirect();

        $indicator->refresh();
        $this->assertEquals('Updated short desc', $indicator->description_short);
        $this->assertEquals('Updated long description', $indicator->description_long);
        $this->assertEquals('Updated context', $indicator->national_context);
        $this->assertEquals('Updated SCB', $indicator->source_name);
        $this->assertEquals('https://updated.scb.se', $indicator->source_url);
        $this->assertEquals('Monthly', $indicator->update_frequency);
    }

    public function test_admin_validates_source_url_format(): void
    {
        $this->seed(\Database\Seeders\IndicatorExplanationSeeder::class);

        $indicator = Indicator::query()->where('slug', 'median_income')->first();

        $response = $this->actingAsAdmin()->put(route('admin.indicators.update', $indicator), [
            'direction' => 'positive',
            'weight' => 0.065,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'is_active' => true,
            'source_url' => 'not-a-url',
        ]);

        $response->assertSessionHasErrors('source_url');
    }

    public function test_admin_validates_description_short_max_length(): void
    {
        $indicator = Indicator::query()->first();

        $response = $this->actingAsAdmin()->put(route('admin.indicators.update', $indicator), [
            'direction' => $indicator->direction,
            'weight' => $indicator->weight,
            'normalization' => $indicator->normalization,
            'normalization_scope' => $indicator->normalization_scope,
            'is_active' => $indicator->is_active,
            'description_short' => str_repeat('a', 101),
        ]);

        $response->assertSessionHasErrors('description_short');
    }

    public function test_admin_page_includes_explanation_fields(): void
    {
        $this->seed(\Database\Seeders\IndicatorExplanationSeeder::class);

        $response = $this->actingAsAdmin()->get(route('admin.indicators'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/indicators')
            ->has('indicators.0.description_short')
            ->has('indicators.0.source_name')
        );
    }

    public function test_explanation_fields_nullable(): void
    {
        $indicator = Indicator::query()->where('slug', 'median_income')->first();

        $response = $this->actingAsAdmin()->put(route('admin.indicators.update', $indicator), [
            'direction' => 'positive',
            'weight' => 0.065,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'is_active' => true,
            'description_short' => null,
            'source_url' => null,
        ]);

        $response->assertRedirect();
    }
}
