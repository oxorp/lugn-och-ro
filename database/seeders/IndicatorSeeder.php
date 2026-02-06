<?php

namespace Database\Seeders;

use App\Models\Indicator;
use Illuminate\Database\Seeder;

class IndicatorSeeder extends Seeder
{
    public function run(): void
    {
        $indicators = [
            [
                'slug' => 'median_income',
                'name' => 'Median Disposable Income',
                'source' => 'scb',
                'unit' => 'SEK',
                'direction' => 'positive',
                'weight' => 0.12,
                'category' => 'income',
                'display_order' => 1,
            ],
            [
                'slug' => 'low_economic_standard_pct',
                'name' => 'Low Economic Standard (%)',
                'source' => 'scb',
                'unit' => 'percent',
                'direction' => 'negative',
                'weight' => 0.08,
                'category' => 'income',
                'display_order' => 2,
            ],
            [
                'slug' => 'employment_rate',
                'name' => 'Employment Rate (20-64)',
                'source' => 'scb',
                'unit' => 'percent',
                'direction' => 'positive',
                'weight' => 0.10,
                'category' => 'employment',
                'display_order' => 3,
            ],
            [
                'slug' => 'education_post_secondary_pct',
                'name' => 'Post-Secondary Education (%)',
                'source' => 'scb',
                'unit' => 'percent',
                'direction' => 'positive',
                'weight' => 0.07,
                'category' => 'education',
                'display_order' => 4,
            ],
            [
                'slug' => 'education_below_secondary_pct',
                'name' => 'Below Secondary Education (%)',
                'source' => 'scb',
                'unit' => 'percent',
                'direction' => 'negative',
                'weight' => 0.03,
                'category' => 'education',
                'display_order' => 5,
            ],
            [
                'slug' => 'foreign_background_pct',
                'name' => 'Foreign Background (%)',
                'source' => 'scb',
                'unit' => 'percent',
                'direction' => 'neutral',
                'weight' => 0.00,
                'category' => 'demographics',
                'display_order' => 6,
            ],
            [
                'slug' => 'population',
                'name' => 'Population',
                'source' => 'scb',
                'unit' => 'number',
                'direction' => 'neutral',
                'weight' => 0.00,
                'category' => 'demographics',
                'display_order' => 7,
            ],
            [
                'slug' => 'rental_tenure_pct',
                'name' => 'Rental Housing (%)',
                'source' => 'scb',
                'unit' => 'percent',
                'direction' => 'neutral',
                'weight' => 0.00,
                'category' => 'housing',
                'display_order' => 8,
            ],
            [
                'slug' => 'school_merit_value_avg',
                'name' => 'Average Merit Value (Schools)',
                'source' => 'skolverket',
                'unit' => 'points',
                'direction' => 'positive',
                'weight' => 0.12,
                'category' => 'education',
                'display_order' => 9,
            ],
            [
                'slug' => 'school_goal_achievement_avg',
                'name' => 'Goal Achievement Rate (Schools)',
                'source' => 'skolverket',
                'unit' => 'percent',
                'direction' => 'positive',
                'weight' => 0.08,
                'category' => 'education',
                'display_order' => 10,
            ],
            [
                'slug' => 'school_teacher_certification_avg',
                'name' => 'Teacher Certification Rate',
                'source' => 'skolverket',
                'unit' => 'percent',
                'direction' => 'positive',
                'weight' => 0.05,
                'category' => 'education',
                'display_order' => 11,
            ],
        ];

        foreach ($indicators as $data) {
            Indicator::query()->updateOrCreate(
                ['slug' => $data['slug']],
                $data
            );
        }
    }
}
