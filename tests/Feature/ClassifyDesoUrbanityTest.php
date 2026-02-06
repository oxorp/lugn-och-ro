<?php

namespace Tests\Feature;

use App\Models\DesoArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassifyDesoUrbanityTest extends TestCase
{
    use RefreshDatabase;

    public function test_density_classification_assigns_urban_tier(): void
    {
        DesoArea::factory()->create([
            'population' => 5000,
            'area_km2' => 1.0, // 5000/km² → urban
        ]);

        $this->artisan('classify:deso-urbanity', ['--method' => 'density'])
            ->assertSuccessful();

        $this->assertDatabaseHas('deso_areas', ['urbanity_tier' => 'urban']);
    }

    public function test_density_classification_assigns_semi_urban_tier(): void
    {
        DesoArea::factory()->create([
            'population' => 500,
            'area_km2' => 2.0, // 250/km² → semi_urban
        ]);

        $this->artisan('classify:deso-urbanity', ['--method' => 'density'])
            ->assertSuccessful();

        $this->assertDatabaseHas('deso_areas', ['urbanity_tier' => 'semi_urban']);
    }

    public function test_density_classification_assigns_rural_tier(): void
    {
        DesoArea::factory()->create([
            'population' => 100,
            'area_km2' => 50.0, // 2/km² → rural
        ]);

        $this->artisan('classify:deso-urbanity', ['--method' => 'density'])
            ->assertSuccessful();

        $this->assertDatabaseHas('deso_areas', ['urbanity_tier' => 'rural']);
    }

    public function test_density_classification_handles_all_tiers(): void
    {
        DesoArea::factory()->create(['population' => 5000, 'area_km2' => 1.0]);
        DesoArea::factory()->create(['population' => 500, 'area_km2' => 2.0]);
        DesoArea::factory()->create(['population' => 100, 'area_km2' => 50.0]);

        $this->artisan('classify:deso-urbanity', ['--method' => 'density'])
            ->assertSuccessful();

        $this->assertEquals(1, DesoArea::where('urbanity_tier', 'urban')->count());
        $this->assertEquals(1, DesoArea::where('urbanity_tier', 'semi_urban')->count());
        $this->assertEquals(1, DesoArea::where('urbanity_tier', 'rural')->count());
    }

    public function test_density_classification_defaults_missing_data_to_rural(): void
    {
        DesoArea::factory()->create([
            'population' => null,
            'area_km2' => null,
            'urbanity_tier' => null,
        ]);

        $this->artisan('classify:deso-urbanity', ['--method' => 'density'])
            ->assertSuccessful();

        $this->assertDatabaseHas('deso_areas', ['urbanity_tier' => 'rural']);
    }

    public function test_tatort_method_returns_failure(): void
    {
        $this->artisan('classify:deso-urbanity', ['--method' => 'tatort'])
            ->assertFailed();
    }

    public function test_classification_is_idempotent(): void
    {
        DesoArea::factory()->create(['population' => 5000, 'area_km2' => 1.0]);

        $this->artisan('classify:deso-urbanity', ['--method' => 'density'])->assertSuccessful();
        $this->artisan('classify:deso-urbanity', ['--method' => 'density'])->assertSuccessful();

        $this->assertEquals(1, DesoArea::where('urbanity_tier', 'urban')->count());
    }

    public function test_no_desos_returns_failure(): void
    {
        $this->artisan('classify:deso-urbanity', ['--method' => 'density'])
            ->assertFailed();
    }
}
