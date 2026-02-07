<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_unit_code' => fake()->unique()->numerify('########'),
            'name' => fake()->company().'skolan',
            'municipality_code' => fake()->numerify('####'),
            'municipality_name' => fake()->city(),
            'type_of_schooling' => 'Grundskola',
            'school_forms' => ['Grundskola'],
            'operator_type' => fake()->randomElement(['Kommunal', 'Fristående']),
            'operator_name' => fake()->company(),
            'status' => 'active',
            'lat' => fake()->latitude(55.3, 69.0),
            'lng' => fake()->longitude(11.0, 24.0),
        ];
    }
}
