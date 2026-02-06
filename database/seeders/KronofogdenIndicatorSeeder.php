<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class KronofogdenIndicatorSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $maxOrder = DB::table('indicators')->max('display_order') ?? 0;

        // New Kronofogden financial distress indicators
        $newIndicators = [
            [
                'slug' => 'debt_rate_pct',
                'name' => 'Kronofogden Debt Rate',
                'description' => 'Estimated % of adult population with debts at Kronofogden',
                'source' => 'kronofogden',
                'source_table' => 'kronofogden_statistics',
                'unit' => 'percent',
                'direction' => 'negative',
                'weight' => 0.0600,
                'normalization' => 'rank_percentile',
                'is_active' => true,
                'display_order' => $maxOrder + 1,
                'category' => 'financial_distress',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'eviction_rate',
                'name' => 'Eviction Rate',
                'description' => 'Estimated evictions per 100,000 inhabitants',
                'source' => 'kronofogden',
                'source_table' => 'kronofogden_statistics',
                'unit' => 'per_100k',
                'direction' => 'negative',
                'weight' => 0.0400,
                'normalization' => 'rank_percentile',
                'is_active' => true,
                'display_order' => $maxOrder + 2,
                'category' => 'financial_distress',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'median_debt_sek',
                'name' => 'Median Debt Amount',
                'description' => 'Median debt at Kronofogden per debtor (kommun-level, SEK)',
                'source' => 'kronofogden',
                'source_table' => 'kronofogden_statistics',
                'unit' => 'SEK',
                'direction' => 'negative',
                'weight' => 0.0200,
                'normalization' => 'rank_percentile',
                'is_active' => true,
                'display_order' => $maxOrder + 3,
                'category' => 'financial_distress',
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

        // Rebalance existing indicator weights (total with Kronofogden = ~0.88, remaining ~0.12 unallocated)
        $weightUpdates = [
            // SCB demographics (was 0.31, now 0.26)
            'median_income' => 0.0750,
            'low_economic_standard_pct' => 0.0500,
            'employment_rate' => 0.0650,
            'education_post_secondary_pct' => 0.0460,
            'education_below_secondary_pct' => 0.0240,
            // School quality (was ~0.19, now 0.17)
            'school_merit_value_avg' => 0.0780,
            'school_goal_achievement_avg' => 0.0540,
            'school_teacher_certification_avg' => 0.0380,
            // Crime/safety (was 0.35, now 0.30)
            'crime_violent_rate' => 0.0700,
            'crime_property_rate' => 0.0500,
            'crime_total_rate' => 0.0300,
            'perceived_safety' => 0.0500,
            'vulnerability_flag' => 0.1000,
        ];

        foreach ($weightUpdates as $slug => $weight) {
            DB::table('indicators')
                ->where('slug', $slug)
                ->update(['weight' => $weight, 'updated_at' => $now]);
        }
    }
}
