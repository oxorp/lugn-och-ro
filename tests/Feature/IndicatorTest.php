<?php

namespace Tests\Feature;

use App\Models\Indicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndicatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_indicator_seeder_creates_all_indicators(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $this->assertDatabaseCount('indicators', 8);
        $this->assertDatabaseHas('indicators', ['slug' => 'median_income', 'direction' => 'positive']);
        $this->assertDatabaseHas('indicators', ['slug' => 'low_economic_standard_pct', 'direction' => 'negative']);
        $this->assertDatabaseHas('indicators', ['slug' => 'foreign_background_pct', 'direction' => 'neutral', 'weight' => 0.0]);
    }

    public function test_indicator_seeder_is_idempotent(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $this->assertDatabaseCount('indicators', 8);
    }

    public function test_indicator_weight_sum_is_half(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $totalWeight = Indicator::query()->sum('weight');

        $this->assertEquals(0.5, (float) $totalWeight);
    }

    public function test_indicator_has_values_relationship(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $indicator = Indicator::query()->where('slug', 'median_income')->first();

        $this->assertNotNull($indicator);
        $this->assertCount(0, $indicator->values);
    }
}
