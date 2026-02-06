<?php

namespace Database\Factories;

use App\Models\SchoolStatistic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolStatistic>
 */
class SchoolStatisticFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_unit_code' => fake()->numerify('########'),
            'academic_year' => '2023/24',
            'merit_value_17' => fake()->randomFloat(1, 150, 300),
            'merit_value_16' => fake()->randomFloat(1, 140, 280),
            'goal_achievement_pct' => fake()->randomFloat(1, 50, 100),
            'eligibility_pct' => fake()->randomFloat(1, 60, 100),
            'teacher_certification_pct' => fake()->randomFloat(1, 40, 100),
            'student_count' => fake()->numberBetween(50, 800),
            'data_source' => 'planned_educations_v3',
        ];
    }
}
