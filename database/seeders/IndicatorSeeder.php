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
                'weight' => 0.15,
                'category' => 'income',
                'display_order' => 1,
            ],
            [
                'slug' => 'low_economic_standard_pct',
                'name' => 'Low Economic Standard (%)',
                'source' => 'scb',
                'unit' => 'percent',
                'direction' => 'negative',
                'weight' => 0.10,
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
                'weight' => 0.10,
                'category' => 'education',
                'display_order' => 4,
            ],
            [
                'slug' => 'education_below_secondary_pct',
                'name' => 'Below Secondary Education (%)',
                'source' => 'scb',
                'unit' => 'percent',
                'direction' => 'negative',
                'weight' => 0.05,
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
        ];

        foreach ($indicators as $data) {
            Indicator::query()->updateOrCreate(
                ['slug' => $data['slug']],
                $data
            );
        }
    }
}
