<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DesoArea>
 */
class DesoAreaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lanCode = str_pad((string) fake()->numberBetween(1, 25), 2, '0', STR_PAD_LEFT);
        $kommunCode = $lanCode.str_pad((string) fake()->numberBetween(1, 99), 2, '0', STR_PAD_LEFT);

        return [
            'deso_code' => $kommunCode.'A'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'deso_name' => fake()->city(),
            'kommun_code' => $kommunCode,
            'kommun_name' => fake()->city(),
            'lan_code' => $lanCode,
            'lan_name' => fake()->city().'s län',
            'area_km2' => fake()->randomFloat(2, 0.1, 50.0),
            'population' => fake()->numberBetween(700, 2700),
            'urbanity_tier' => fake()->randomElement(['urban', 'semi_urban', 'rural']),
        ];
    }
}
