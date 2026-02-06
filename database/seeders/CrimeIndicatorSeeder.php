<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CrimeIndicatorSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Get current max display_order
        $maxOrder = DB::table('indicators')->max('display_order') ?? 0;

        // New crime indicators
        $newIndicators = [
            [
                'slug' => 'crime_violent_rate',
                'name' => 'Violent Crime Rate',
                'description' => 'Estimated violent crimes per 100,000 inhabitants (person + robbery + sexual crimes)',
                'source' => 'bra',
                'source_table' => 'crime_statistics',
                'unit' => 'per_100k',
                'direction' => 'negative',
                'weight' => 0.0800,
                'normalization' => 'rank_percentile',
                'is_active' => true,
                'display_order' => $maxOrder + 1,
                'category' => 'crime',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'crime_property_rate',
                'name' => 'Property Crime Rate',
                'description' => 'Estimated property crimes per 100,000 inhabitants (theft + criminal damage)',
                'source' => 'bra',
                'source_table' => 'crime_statistics',
                'unit' => 'per_100k',
                'direction' => 'negative',
                'weight' => 0.0600,
                'normalization' => 'rank_percentile',
                'is_active' => true,
                'display_order' => $maxOrder + 2,
                'category' => 'crime',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'crime_total_rate',
                'name' => 'Total Crime Rate',
                'description' => 'Estimated total reported crimes per 100,000 inhabitants',
                'source' => 'bra',
                'source_table' => 'crime_statistics',
                'unit' => 'per_100k',
                'direction' => 'negative',
                'weight' => 0.0400,
                'normalization' => 'rank_percentile',
                'is_active' => true,
                'display_order' => $maxOrder + 3,
                'category' => 'crime',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'perceived_safety',
                'name' => 'Perceived Safety (NTU)',
                'description' => 'Estimated % who feel safe outdoors at night, from NTU survey data',
                'source' => 'bra_ntu',
                'source_table' => 'ntu_survey_data',
                'unit' => 'percent',
                'direction' => 'positive',
                'weight' => 0.0700,
                'normalization' => 'rank_percentile',
                'is_active' => true,
                'display_order' => $maxOrder + 4,
                'category' => 'safety',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'vulnerability_flag',
                'name' => 'Police Vulnerability Area',
                'description' => 'Polisen classification: 0=not flagged, 1=utsatt, 2=särskilt utsatt',
                'source' => 'polisen',
                'source_table' => 'vulnerability_areas',
                'unit' => 'flag',
                'direction' => 'negative',
                'weight' => 0.1000,
                'normalization' => 'rank_percentile',
                'is_active' => true,
                'display_order' => $maxOrder + 5,
                'category' => 'crime',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($newIndicators as $indicator) {
            DB::table('indicators')->upsert(
                [$indicator],
                ['slug'],
                ['name', 'description', 'source', 'source_table', 'unit', 'direction', 'weight', 'normalization', 'is_active', 'display_order', 'category', 'updated_at']
            );
        }

        // Rebalance existing indicator weights
        $weightUpdates = [
            'median_income' => 0.0900,
            'low_economic_standard_pct' => 0.0600,
            'employment_rate' => 0.0800,
            'education_post_secondary_pct' => 0.0560,
            'education_below_secondary_pct' => 0.0240,
            'school_merit_value_avg' => 0.0912,
            'school_goal_achievement_avg' => 0.0608,
            'school_teacher_certification_avg' => 0.0380,
        ];

        foreach ($weightUpdates as $slug => $weight) {
            DB::table('indicators')
                ->where('slug', $slug)
                ->update(['weight' => $weight, 'updated_at' => $now]);
        }
    }
}
