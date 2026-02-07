<?php

namespace Database\Factories;

use App\Models\Poi;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Poi>
 */
class PoiFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => 'osm_node_'.fake()->unique()->numberBetween(100000, 9999999),
            'source' => 'osm',
            'category' => fake()->randomElement(['grocery', 'healthcare', 'restaurant', 'fitness']),
            'name' => fake()->company(),
            'lat' => fake()->latitude(55.3, 69.0),
            'lng' => fake()->longitude(11.0, 24.0),
            'status' => 'active',
            'last_verified_at' => now(),
        ];
    }

    public function grocery(): static
    {
        return $this->state(fn () => ['category' => 'grocery']);
    }

    public function healthcare(): static
    {
        return $this->state(fn () => ['category' => 'healthcare']);
    }

    public function gambling(): static
    {
        return $this->state(fn () => ['category' => 'gambling']);
    }
}
