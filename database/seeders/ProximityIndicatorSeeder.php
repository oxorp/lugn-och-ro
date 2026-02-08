<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProximityIndicatorSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $maxOrder = DB::table('indicators')->max('display_order') ?? 0;

        $proximityIndicators = [
            [
                'slug' => 'prox_school',
                'name' => 'School Proximity & Quality',
                'description' => 'Distance-decayed quality score of nearest grundskola within 2km',
                'source' => 'proximity',
                'source_table' => 'schools',
                'unit' => 'score',
                'direction' => 'positive',
                'weight' => 0.1000,
                'normalization' => 'none',
                'normalization_scope' => 'national',
                'is_active' => true,
                'display_order' => $maxOrder + 1,
                'category' => 'proximity',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'prox_green_space',
                'name' => 'Green Space Access',
                'description' => 'Distance to nearest park or nature reserve within 1km',
                'source' => 'proximity',
                'source_table' => 'pois',
                'unit' => 'score',
                'direction' => 'positive',
                'weight' => 0.0400,
                'normalization' => 'none',
                'normalization_scope' => 'national',
                'is_active' => true,
                'display_order' => $maxOrder + 2,
                'category' => 'proximity',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'prox_transit',
                'name' => 'Transit Access',
                'description' => 'Distance to nearest transit stop with mode weighting within 1km',
                'source' => 'proximity',
                'source_table' => 'pois',
                'unit' => 'score',
                'direction' => 'positive',
                'weight' => 0.0500,
                'normalization' => 'none',
                'normalization_scope' => 'national',
                'is_active' => true,
                'display_order' => $maxOrder + 3,
                'category' => 'proximity',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'prox_grocery',
                'name' => 'Grocery Access',
                'description' => 'Distance to nearest grocery store within 1km',
                'source' => 'proximity',
                'source_table' => 'pois',
                'unit' => 'score',
                'direction' => 'positive',
                'weight' => 0.0300,
                'normalization' => 'none',
                'normalization_scope' => 'national',
                'is_active' => true,
                'display_order' => $maxOrder + 4,
                'category' => 'proximity',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'prox_negative_poi',
                'name' => 'Negative POI Proximity',
                'description' => 'Penalty from negative POIs within 500m (gambling, pawn shops, etc)',
                'source' => 'proximity',
                'source_table' => 'pois',
                'unit' => 'score',
                'direction' => 'negative',
                'weight' => 0.0400,
                'normalization' => 'none',
                'normalization_scope' => 'national',
                'is_active' => true,
                'display_order' => $maxOrder + 5,
                'category' => 'proximity',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'prox_positive_poi',
                'name' => 'Positive POI Density',
                'description' => 'Bonus from positive amenities within 1km (restaurants, fitness, cafes)',
                'source' => 'proximity',
                'source_table' => 'pois',
                'unit' => 'score',
                'direction' => 'positive',
                'weight' => 0.0400,
                'normalization' => 'none',
                'normalization_scope' => 'national',
                'is_active' => true,
                'display_order' => $maxOrder + 6,
                'category' => 'proximity',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($proximityIndicators as $indicator) {
            DB::table('indicators')->upsert(
                [$indicator],
                ['slug'],
                ['name', 'description', 'source', 'source_table', 'unit', 'direction', 'weight', 'normalization', 'normalization_scope', 'is_active', 'display_order', 'category', 'updated_at']
            );
        }

        // Rebalance area-level indicator weights so area total ≈ 0.70
        // Current area weights sum to ~0.93. Scale factor: 0.70 / 0.93 ≈ 0.753
        $scaleFactor = 0.753;

        $areaWeightUpdates = [
            // SCB demographics
            'median_income' => round(0.0650 * $scaleFactor, 4),
            'low_economic_standard_pct' => round(0.0400 * $scaleFactor, 4),
            'employment_rate' => round(0.0550 * $scaleFactor, 4),
            'education_post_secondary_pct' => round(0.0380 * $scaleFactor, 4),
            'education_below_secondary_pct' => round(0.0220 * $scaleFactor, 4),
            // School quality
            'school_merit_value_avg' => round(0.0700 * $scaleFactor, 4),
            'school_goal_achievement_avg' => round(0.0450 * $scaleFactor, 4),
            'school_teacher_certification_avg' => round(0.0350 * $scaleFactor, 4),
            // Crime/safety
            'crime_violent_rate' => round(0.0600 * $scaleFactor, 4),
            'crime_property_rate' => round(0.0450 * $scaleFactor, 4),
            'crime_total_rate' => round(0.0250 * $scaleFactor, 4),
            'perceived_safety' => round(0.0450 * $scaleFactor, 4),
            'vulnerability_flag' => round(0.0950 * $scaleFactor, 4),
            // Kronofogden
            'debt_rate_pct' => round(0.0500 * $scaleFactor, 4),
            'eviction_rate' => round(0.0300 * $scaleFactor, 4),
            'median_debt_sek' => round(0.0200 * $scaleFactor, 4),
            // POI density
            'grocery_density' => round(0.0400 * $scaleFactor, 4),
            'healthcare_density' => round(0.0300 * $scaleFactor, 4),
            'restaurant_density' => round(0.0200 * $scaleFactor, 4),
            'fitness_density' => round(0.0200 * $scaleFactor, 4),
            'transit_stop_density' => round(0.0400 * $scaleFactor, 4),
            'gambling_density' => round(0.0200 * $scaleFactor, 4),
            'pawn_shop_density' => round(0.0100 * $scaleFactor, 4),
            'fast_food_density' => round(0.0100 * $scaleFactor, 4),
        ];

        foreach ($areaWeightUpdates as $slug => $weight) {
            DB::table('indicators')
                ->where('slug', $slug)
                ->update(['weight' => $weight, 'updated_at' => $now]);
        }
    }
}
