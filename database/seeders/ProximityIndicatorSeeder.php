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
                'description_short' => 'Proximity to nearest school weighted by quality.',
                'description_long' => 'Measures how close the selected location is to the nearest grundskola, weighted by school quality metrics (merit value, goal achievement, teacher certification). Closer, higher-quality schools score better.',
                'methodology_note' => 'Uses haversine distance to all schools within 3 km. Score combines distance penalty with quality bonus from Skolverket statistics.',
                'national_context' => 'Median distance to nearest school: ~500m in urban areas, ~2km in rural areas.',
                'source' => 'proximity',
                'source_name' => 'Skolverket',
                'source_url' => 'https://www.skolverket.se',
                'source_table' => 'schools',
                'unit' => 'score',
                'direction' => 'positive',
                'weight' => 0.1000,
                'normalization' => 'none',
                'normalization_scope' => 'national',
                'is_active' => true,
                'display_order' => $maxOrder + 1,
                'category' => 'proximity',
                'data_vintage' => '2024',
                'update_frequency' => 'Updated annually',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'prox_green_space',
                'name' => 'Green Space Access',
                'description' => 'Distance to nearest park or nature reserve within 1km',
                'description_short' => 'Access to parks and green spaces nearby.',
                'description_long' => 'Measures the availability and proximity of parks, nature reserves, forests, and other green spaces near the selected location. More and closer green areas improve the score.',
                'methodology_note' => 'Based on OpenStreetMap land-use data for parks, forests, and nature reserves within a 1 km radius.',
                'national_context' => 'Swedish municipal average: ~85% of residents within 300m of green space.',
                'source' => 'proximity',
                'source_name' => 'OpenStreetMap (OSM)',
                'source_url' => 'https://www.openstreetmap.org',
                'source_table' => 'pois',
                'unit' => 'score',
                'direction' => 'positive',
                'weight' => 0.0400,
                'normalization' => 'none',
                'normalization_scope' => 'national',
                'is_active' => true,
                'display_order' => $maxOrder + 2,
                'category' => 'proximity',
                'data_vintage' => '2025',
                'update_frequency' => 'Updated monthly',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'prox_transit',
                'name' => 'Transit Access',
                'description' => 'Distance to nearest transit stop with mode weighting within 1km',
                'description_short' => 'Access to public transit stops nearby.',
                'description_long' => 'Measures proximity to bus stops, tram stops, subway stations, and commuter rail stations. More stops and shorter distances indicate better public transport access.',
                'methodology_note' => 'Counts transit stops within catchment radius using OpenStreetMap data. Weighted by stop type (rail stations score higher than bus stops).',
                'national_context' => 'Urban areas average 15+ transit stops within 500m; rural areas often have 0-2.',
                'source' => 'proximity',
                'source_name' => 'OpenStreetMap (OSM)',
                'source_url' => 'https://www.openstreetmap.org',
                'source_table' => 'pois',
                'unit' => 'score',
                'direction' => 'positive',
                'weight' => 0.0500,
                'normalization' => 'none',
                'normalization_scope' => 'national',
                'is_active' => true,
                'display_order' => $maxOrder + 3,
                'category' => 'proximity',
                'data_vintage' => '2025',
                'update_frequency' => 'Updated monthly',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'prox_grocery',
                'name' => 'Grocery Access',
                'description' => 'Distance to nearest grocery store within 1km',
                'description_short' => 'Access to grocery stores nearby.',
                'description_long' => 'Measures proximity to supermarkets and grocery stores near the selected location. Closer and more stores improve the score, reflecting everyday convenience.',
                'methodology_note' => 'Based on OpenStreetMap POI data for supermarkets and grocery stores within a 1.5 km radius.',
                'national_context' => 'Urban areas typically have 3-5 grocery stores within 1 km; rural areas may have none within 3 km.',
                'source' => 'proximity',
                'source_name' => 'OpenStreetMap (OSM)',
                'source_url' => 'https://www.openstreetmap.org',
                'source_table' => 'pois',
                'unit' => 'score',
                'direction' => 'positive',
                'weight' => 0.0300,
                'normalization' => 'none',
                'normalization_scope' => 'national',
                'is_active' => true,
                'display_order' => $maxOrder + 4,
                'category' => 'proximity',
                'data_vintage' => '2025',
                'update_frequency' => 'Updated monthly',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'prox_negative_poi',
                'name' => 'Negative POI Proximity',
                'description' => 'Penalty from negative POIs within 500m (gambling, pawn shops, etc)',
                'description_short' => 'Presence of undesirable establishments nearby.',
                'description_long' => 'Penalizes locations near gambling venues, pawn shops, and late-night fast food outlets. More nearby negative POIs and shorter distances result in a lower score.',
                'methodology_note' => 'Counts gambling, pawn shop, and late-night fast food POIs within 500m with distance decay. Each POI applies a 20-point penalty scaled by proximity.',
                'national_context' => 'Most residential areas have 0-1 negative POIs within 500m. Central city areas may have 10+.',
                'source' => 'proximity',
                'source_name' => 'OpenStreetMap (OSM)',
                'source_url' => 'https://www.openstreetmap.org',
                'source_table' => 'pois',
                'unit' => 'score',
                'direction' => 'negative',
                'weight' => 0.0400,
                'normalization' => 'none',
                'normalization_scope' => 'national',
                'is_active' => true,
                'display_order' => $maxOrder + 5,
                'category' => 'proximity',
                'data_vintage' => '2025',
                'update_frequency' => 'Updated monthly',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'prox_positive_poi',
                'name' => 'Positive POI Density',
                'description' => 'Bonus from positive amenities within 1km (restaurants, fitness, cafes)',
                'description_short' => 'Access to positive amenities nearby.',
                'description_long' => 'Measures proximity to restaurants, fitness centers, healthcare facilities, and other positive amenities. More nearby amenities and shorter distances improve the score.',
                'methodology_note' => 'Counts restaurants, fitness, healthcare, and cultural POIs within 1.5 km radius using OpenStreetMap data.',
                'national_context' => 'Urban areas average 20+ positive amenities within 1 km; suburban areas 5-10.',
                'source' => 'proximity',
                'source_name' => 'OpenStreetMap (OSM)',
                'source_url' => 'https://www.openstreetmap.org',
                'source_table' => 'pois',
                'unit' => 'score',
                'direction' => 'positive',
                'weight' => 0.0400,
                'normalization' => 'none',
                'normalization_scope' => 'national',
                'is_active' => true,
                'display_order' => $maxOrder + 6,
                'category' => 'proximity',
                'data_vintage' => '2025',
                'update_frequency' => 'Updated monthly',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($proximityIndicators as $indicator) {
            DB::table('indicators')->upsert(
                [$indicator],
                ['slug'],
                ['name', 'description', 'description_short', 'description_long', 'methodology_note', 'national_context', 'source', 'source_name', 'source_url', 'source_table', 'unit', 'direction', 'weight', 'normalization', 'normalization_scope', 'is_active', 'display_order', 'category', 'data_vintage', 'update_frequency', 'updated_at']
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
