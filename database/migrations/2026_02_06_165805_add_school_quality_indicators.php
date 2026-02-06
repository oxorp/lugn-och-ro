<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now()->toDateTimeString();

        // Add new school quality indicators
        $newIndicators = [
            [
                'slug' => 'school_merit_value_avg',
                'name' => 'Average Merit Value (Schools)',
                'source' => 'skolverket',
                'unit' => 'points',
                'direction' => 'positive',
                'weight' => 0.12,
                'normalization' => 'rank_percentile',
                'category' => 'education',
                'is_active' => true,
                'display_order' => 9,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'school_goal_achievement_avg',
                'name' => 'Goal Achievement Rate (Schools)',
                'source' => 'skolverket',
                'unit' => 'percent',
                'direction' => 'positive',
                'weight' => 0.08,
                'normalization' => 'rank_percentile',
                'category' => 'education',
                'is_active' => true,
                'display_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'school_teacher_certification_avg',
                'name' => 'Teacher Certification Rate',
                'source' => 'skolverket',
                'unit' => 'percent',
                'direction' => 'positive',
                'weight' => 0.05,
                'normalization' => 'rank_percentile',
                'category' => 'education',
                'is_active' => true,
                'display_order' => 11,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($newIndicators as $indicator) {
            DB::table('indicators')->updateOrInsert(
                ['slug' => $indicator['slug']],
                $indicator
            );
        }

        // Rebalance existing weights
        $weightUpdates = [
            'median_income' => 0.12,
            'low_economic_standard_pct' => 0.08,
            'employment_rate' => 0.10,
            'education_post_secondary_pct' => 0.07,
            'education_below_secondary_pct' => 0.03,
        ];

        foreach ($weightUpdates as $slug => $weight) {
            DB::table('indicators')
                ->where('slug', $slug)
                ->update(['weight' => $weight, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('indicators')
            ->whereIn('slug', [
                'school_merit_value_avg',
                'school_goal_achievement_avg',
                'school_teacher_certification_avg',
            ])
            ->delete();

        // Restore original weights
        $originalWeights = [
            'median_income' => 0.15,
            'low_economic_standard_pct' => 0.10,
            'employment_rate' => 0.10,
            'education_post_secondary_pct' => 0.10,
            'education_below_secondary_pct' => 0.05,
        ];

        foreach ($originalWeights as $slug => $weight) {
            DB::table('indicators')
                ->where('slug', $slug)
                ->update(['weight' => $weight]);
        }
    }
};
