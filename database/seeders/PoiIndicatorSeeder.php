<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PoiIndicatorSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $maxOrder = DB::table('indicators')->max('display_order') ?? 0;

        $newIndicators = [
            [
                'slug' => 'grocery_density',
                'name' => 'Grocery Access',
                'description' => 'Grocery stores per 1,000 residents within catchment area',
                'source' => 'osm',
                'source_table' => 'pois',
                'unit' => 'per_1000',
                'direction' => 'positive',
                'weight' => 0.0400,
                'normalization' => 'rank_percentile',
                'normalization_scope' => 'urbanity_stratified',
                'is_active' => true,
                'display_order' => $maxOrder + 1,
                'category' => 'amenities',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'healthcare_density',
                'name' => 'Healthcare Access',
                'description' => 'Healthcare facilities per 1,000 residents within catchment area',
                'source' => 'osm',
                'source_table' => 'pois',
                'unit' => 'per_1000',
                'direction' => 'positive',
                'weight' => 0.0300,
                'normalization' => 'rank_percentile',
                'normalization_scope' => 'urbanity_stratified',
                'is_active' => true,
                'display_order' => $maxOrder + 2,
                'category' => 'amenities',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'restaurant_density',
                'name' => 'Restaurant & Café Density',
                'description' => 'Restaurants and cafés per 1,000 residents within catchment area',
                'source' => 'osm',
                'source_table' => 'pois',
                'unit' => 'per_1000',
                'direction' => 'positive',
                'weight' => 0.0200,
                'normalization' => 'rank_percentile',
                'normalization_scope' => 'urbanity_stratified',
                'is_active' => true,
                'display_order' => $maxOrder + 3,
                'category' => 'amenities',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'fitness_density',
                'name' => 'Fitness & Sports Access',
                'description' => 'Gyms and sports centres per 1,000 residents within catchment area',
                'source' => 'osm',
                'source_table' => 'pois',
                'unit' => 'per_1000',
                'direction' => 'positive',
                'weight' => 0.0200,
                'normalization' => 'rank_percentile',
                'normalization_scope' => 'urbanity_stratified',
                'is_active' => true,
                'display_order' => $maxOrder + 4,
                'category' => 'amenities',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'transit_stop_density',
                'name' => 'Public Transport Stops',
                'description' => 'Public transport stops per 1,000 residents within catchment area',
                'source' => 'osm',
                'source_table' => 'pois',
                'unit' => 'per_1000',
                'direction' => 'positive',
                'weight' => 0.0400,
                'normalization' => 'rank_percentile',
                'normalization_scope' => 'urbanity_stratified',
                'is_active' => true,
                'display_order' => $maxOrder + 5,
                'category' => 'transport',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'gambling_density',
                'name' => 'Gambling Venue Density',
                'description' => 'Gambling venues per 1,000 residents within catchment area',
                'source' => 'osm',
                'source_table' => 'pois',
                'unit' => 'per_1000',
                'direction' => 'negative',
                'weight' => 0.0200,
                'normalization' => 'rank_percentile',
                'normalization_scope' => 'urbanity_stratified',
                'is_active' => true,
                'display_order' => $maxOrder + 6,
                'category' => 'amenities',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'pawn_shop_density',
                'name' => 'Pawn Shop Density',
                'description' => 'Pawn shops per 1,000 residents within catchment area',
                'source' => 'osm',
                'source_table' => 'pois',
                'unit' => 'per_1000',
                'direction' => 'negative',
                'weight' => 0.0100,
                'normalization' => 'rank_percentile',
                'normalization_scope' => 'urbanity_stratified',
                'is_active' => true,
                'display_order' => $maxOrder + 7,
                'category' => 'amenities',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'fast_food_density',
                'name' => 'Late-Night Fast Food Density',
                'description' => 'Fast food restaurants per 1,000 residents within catchment area',
                'source' => 'osm',
                'source_table' => 'pois',
                'unit' => 'per_1000',
                'direction' => 'negative',
                'weight' => 0.0100,
                'normalization' => 'rank_percentile',
                'normalization_scope' => 'urbanity_stratified',
                'is_active' => true,
                'display_order' => $maxOrder + 8,
                'category' => 'amenities',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($newIndicators as $indicator) {
            DB::table('indicators')->upsert(
                [$indicator],
                ['slug'],
                ['name', 'description', 'source', 'source_table', 'unit', 'direction', 'weight', 'normalization', 'normalization_scope', 'is_active', 'display_order', 'category', 'updated_at']
            );
        }

        // Rebalance existing indicator weights
        // New total POI weight: 0.19, taken from existing categories
        $weightUpdates = [
            // SCB demographics (was 0.26, now 0.22)
            'median_income' => 0.0650,
            'low_economic_standard_pct' => 0.0400,
            'employment_rate' => 0.0550,
            'education_post_secondary_pct' => 0.0380,
            'education_below_secondary_pct' => 0.0220,
            // School quality (was 0.17, now 0.15)
            'school_merit_value_avg' => 0.0700,
            'school_goal_achievement_avg' => 0.0450,
            'school_teacher_certification_avg' => 0.0350,
            // Crime/safety (was 0.30, now 0.27)
            'crime_violent_rate' => 0.0600,
            'crime_property_rate' => 0.0450,
            'crime_total_rate' => 0.0250,
            'perceived_safety' => 0.0450,
            'vulnerability_flag' => 0.0950,
            // Kronofogden (was 0.12, now 0.10)
            'debt_rate_pct' => 0.0500,
            'eviction_rate' => 0.0300,
            'median_debt_sek' => 0.0200,
        ];

        foreach ($weightUpdates as $slug => $weight) {
            DB::table('indicators')
                ->where('slug', $slug)
                ->update(['weight' => $weight, 'updated_at' => $now]);
        }
    }
}
