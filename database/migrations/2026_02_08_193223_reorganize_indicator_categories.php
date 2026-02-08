<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reorganize indicator categories from 11 fragmented groups into 6 clean categories:
 * safety, economy, education, environment, proximity, contextual.
 *
 * Also updates is_free_preview flags to match the new 5-category scheme (2 free per category).
 */
return new class extends Migration
{
    /**
     * Category mapping: slug => new category.
     *
     * @var array<string, string>
     */
    private array $mapping = [
        // Safety & Crime
        'crime_violent_rate' => 'safety',
        'crime_property_rate' => 'safety',
        'crime_total_rate' => 'safety',
        'vulnerability_flag' => 'safety',
        'perceived_safety' => 'safety',
        'gambling_density' => 'safety',
        'pawn_shop_density' => 'safety',
        'fast_food_density' => 'safety',

        // Economy & Employment
        'median_income' => 'economy',
        'low_economic_standard_pct' => 'economy',
        'employment_rate' => 'economy',
        'debt_rate_pct' => 'economy',
        'eviction_rate' => 'economy',
        'median_debt_sek' => 'economy',

        // Education & Schools
        'education_post_secondary_pct' => 'education',
        'education_below_secondary_pct' => 'education',
        'school_merit_value_avg' => 'education',
        'school_goal_achievement_avg' => 'education',
        'school_teacher_certification_avg' => 'education',

        // Environment & Services
        'grocery_density' => 'environment',
        'healthcare_density' => 'environment',
        'restaurant_density' => 'environment',
        'fitness_density' => 'environment',
        'transit_stop_density' => 'environment',

        // Contextual (internal use only, not displayed)
        'foreign_background_pct' => 'contextual',
        'population' => 'contextual',
        'rental_tenure_pct' => 'contextual',

        // Proximity (pin-level, separate computation)
        'prox_school' => 'proximity',
        'prox_green_space' => 'proximity',
        'prox_transit' => 'proximity',
        'prox_grocery' => 'proximity',
        'prox_negative_poi' => 'proximity',
        'prox_positive_poi' => 'proximity',
    ];

    /**
     * Free preview indicators: 2 per display category.
     *
     * @var array<string>
     */
    private array $freePreviewSlugs = [
        'perceived_safety',
        'crime_violent_rate',
        'median_income',
        'employment_rate',
        'school_merit_value_avg',
        'school_teacher_certification_avg',
        'grocery_density',
        'transit_stop_density',
    ];

    /**
     * Reverse mapping: new category => old category(ies) for rollback.
     *
     * @var array<string, array<string, string>>
     */
    private array $reverseMapping = [
        'crime_violent_rate' => 'crime',
        'crime_property_rate' => 'crime',
        'crime_total_rate' => 'crime',
        'vulnerability_flag' => 'crime',
        'perceived_safety' => 'safety',
        'gambling_density' => 'amenities',
        'pawn_shop_density' => 'amenities',
        'fast_food_density' => 'amenities',
        'median_income' => 'income',
        'low_economic_standard_pct' => 'income',
        'employment_rate' => 'employment',
        'debt_rate_pct' => 'financial_distress',
        'eviction_rate' => 'financial_distress',
        'median_debt_sek' => 'financial_distress',
        'education_post_secondary_pct' => 'education',
        'education_below_secondary_pct' => 'education',
        'school_merit_value_avg' => 'education',
        'school_goal_achievement_avg' => 'education',
        'school_teacher_certification_avg' => 'education',
        'grocery_density' => 'amenities',
        'healthcare_density' => 'amenities',
        'restaurant_density' => 'amenities',
        'fitness_density' => 'amenities',
        'transit_stop_density' => 'transport',
        'foreign_background_pct' => 'demographics',
        'population' => 'demographics',
        'rental_tenure_pct' => 'housing',
        'prox_school' => 'proximity',
        'prox_green_space' => 'proximity',
        'prox_transit' => 'proximity',
        'prox_grocery' => 'proximity',
        'prox_negative_poi' => 'proximity',
        'prox_positive_poi' => 'proximity',
    ];

    public function up(): void
    {
        // Update categories
        foreach ($this->mapping as $slug => $category) {
            DB::table('indicators')
                ->where('slug', $slug)
                ->update(['category' => $category]);
        }

        // Reset all free preview flags, then set the new ones
        DB::table('indicators')->update(['is_free_preview' => false]);

        DB::table('indicators')
            ->whereIn('slug', $this->freePreviewSlugs)
            ->update(['is_free_preview' => true]);
    }

    public function down(): void
    {
        // Restore original categories
        foreach ($this->reverseMapping as $slug => $category) {
            DB::table('indicators')
                ->where('slug', $slug)
                ->update(['category' => $category]);
        }
    }
};
