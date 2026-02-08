<?php

namespace Database\Seeders;

use App\Models\PoiCategory;
use Illuminate\Database\Seeder;

class SafetySensitivitySeeder extends Seeder
{
    /**
     * Set initial safety_sensitivity values for all POI categories.
     *
     * Negative POIs always get 0.0 (their badness doesn't increase in unsafe areas).
     * Positive POIs get values from 0.3 (necessities) to 1.5 (discretionary/night).
     */
    public function run(): void
    {
        $sensitivities = [
            // Necessities — low sensitivity (you go regardless)
            'grocery' => 0.30,
            'premium_grocery' => 0.30,
            'pharmacy' => 0.30,
            'healthcare' => 0.30,

            // Public transport — low-medium (fixed schedule, others around)
            'public_transport_stop' => 0.50,

            // Schools — medium (kids walk there daily)
            'school_grundskola' => 0.80,

            // Public facilities — medium
            'library' => 0.80,
            'swimming' => 0.80,
            'marina' => 0.80,

            // Discretionary outdoor — standard
            'park' => 1.00,
            'nature_reserve' => 1.00,
            'fitness' => 1.00,
            'bookshop' => 1.00,

            // Discretionary, often evening — high
            'restaurant' => 1.20,
            'cultural_venue' => 1.50,

            // Nightlife — very high
            'nightclub' => 1.50,

            // All negative-signal categories: 0.0 (no safety modulation)
            'gambling' => 0.00,
            'pawn_shop' => 0.00,
            'fast_food_late' => 0.00,
            'sex_shop' => 0.00,
            'homeless_shelter' => 0.00,
            'airport' => 0.00,
            'wastewater_plant' => 0.00,
            'landfill' => 0.00,
            'prison' => 0.00,
            'wind_turbine' => 0.00,
            'paper_mill' => 0.00,
            'shooting_range' => 0.00,
            'quarry' => 0.00,
            'waste_incinerator' => 0.00,
            'recycling_station' => 0.00,
        ];

        foreach ($sensitivities as $slug => $sensitivity) {
            PoiCategory::where('slug', $slug)->update([
                'safety_sensitivity' => $sensitivity,
            ]);
        }
    }
}
