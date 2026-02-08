<?php

namespace Database\Seeders;

use App\Models\Indicator;
use Illuminate\Database\Seeder;

/**
 * Rebalance indicator weights after category reorganization.
 *
 * Category weight budget (must sum to 1.00):
 *   Safety:      25% (8 indicators)
 *   Economy:     20% (6 indicators)
 *   Education:   15% (5 indicators)
 *   Environment: 10% (5 indicators)
 *   Proximity:   30% (6 indicators)
 *   Contextual:   0% (3 indicators)
 *
 * Individual weights are scaled from the task spec proportions
 * to hit these exact category totals.
 */
class CategoryWeightRebalanceSeeder extends Seeder
{
    /**
     * @var array<string, float>
     */
    private array $weights = [
        // Safety & Crime — 25.0%
        'crime_violent_rate' => 0.048,
        'crime_property_rate' => 0.036,
        'crime_total_rate' => 0.020,
        'vulnerability_flag' => 0.077,
        'perceived_safety' => 0.036,
        'gambling_density' => 0.016,
        'pawn_shop_density' => 0.008,
        'fast_food_density' => 0.009,

        // Economy & Employment — 20.0%
        'median_income' => 0.050,
        'low_economic_standard_pct' => 0.031,
        'employment_rate' => 0.042,
        'debt_rate_pct' => 0.038,
        'eviction_rate' => 0.023,
        'median_debt_sek' => 0.016,

        // Education & Schools — 15.0%
        'education_post_secondary_pct' => 0.027,
        'education_below_secondary_pct' => 0.016,
        'school_merit_value_avg' => 0.050,
        'school_goal_achievement_avg' => 0.032,
        'school_teacher_certification_avg' => 0.025,

        // Environment & Services — 10.0%
        'grocery_density' => 0.027,
        'healthcare_density' => 0.020,
        'restaurant_density' => 0.013,
        'fitness_density' => 0.013,
        'transit_stop_density' => 0.027,

        // Proximity — 30.0%
        'prox_school' => 0.100,
        'prox_green_space' => 0.040,
        'prox_transit' => 0.050,
        'prox_grocery' => 0.030,
        'prox_negative_poi' => 0.040,
        'prox_positive_poi' => 0.040,

        // Contextual — 0%
        'foreign_background_pct' => 0.000,
        'population' => 0.000,
        'rental_tenure_pct' => 0.000,
    ];

    public function run(): void
    {
        $updated = 0;

        foreach ($this->weights as $slug => $weight) {
            $count = Indicator::where('slug', $slug)->update(['weight' => $weight]);
            $updated += $count;
        }

        // Verify totals per category
        $categories = ['safety', 'economy', 'education', 'environment', 'proximity'];
        $grandTotal = 0;

        foreach ($categories as $cat) {
            $catTotal = (float) Indicator::where('is_active', true)
                ->where('category', $cat)
                ->sum('weight');
            $grandTotal += $catTotal;
            $this->command->info("{$cat}: ".round($catTotal * 100, 1).'%');
        }

        $this->command->info("Grand total: {$grandTotal} (target 1.00)");

        if (abs($grandTotal - 1.0) > 0.01) {
            $this->command->warn("Weight total is {$grandTotal}, expected 1.00!");
        } else {
            $this->command->info('Weights balanced correctly.');
        }
    }
}
