<?php

namespace Database\Seeders;

use App\Models\PoiCategory;
use Illuminate\Database\Seeder;

class PoiCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'slug' => 'grocery',
                'name' => 'Grocery Stores',
                'indicator_slug' => 'grocery_density',
                'signal' => 'positive',
                'osm_tags' => ['shop' => ['supermarket', 'convenience', 'greengrocer']],
                'catchment_km' => 1.50,
                'description' => 'Supermarkets, convenience stores, and greengrocers',
            ],
            [
                'slug' => 'healthcare',
                'name' => 'Healthcare Facilities',
                'indicator_slug' => 'healthcare_density',
                'signal' => 'positive',
                'osm_tags' => ['amenity' => ['hospital', 'clinic', 'doctors', 'pharmacy']],
                'catchment_km' => 3.00,
                'description' => 'Hospitals, clinics, doctors, and pharmacies',
            ],
            [
                'slug' => 'restaurant',
                'name' => 'Restaurants & Cafés',
                'indicator_slug' => 'restaurant_density',
                'signal' => 'positive',
                'osm_tags' => ['amenity' => ['restaurant', 'cafe']],
                'catchment_km' => 1.00,
                'description' => 'Restaurants and cafés (not fast food)',
            ],
            [
                'slug' => 'fitness',
                'name' => 'Gyms & Fitness',
                'indicator_slug' => 'fitness_density',
                'signal' => 'positive',
                'osm_tags' => ['leisure' => ['fitness_centre', 'sports_centre'], 'sport' => ['padel']],
                'catchment_km' => 2.00,
                'description' => 'Gyms, fitness centres, sports centres, padel courts',
            ],
            [
                'slug' => 'school_grundskola',
                'name' => 'Primary Schools',
                'indicator_slug' => null,
                'signal' => 'positive',
                'osm_tags' => null,
                'catchment_km' => 2.00,
                'is_active' => false,
                'description' => 'Already handled by Skolverket indicators',
            ],
            [
                'slug' => 'gambling',
                'name' => 'Gambling Venues',
                'indicator_slug' => 'gambling_density',
                'signal' => 'negative',
                'osm_tags' => ['shop' => ['bookmaker', 'lottery'], 'amenity' => ['gambling', 'casino']],
                'catchment_km' => 1.50,
                'description' => 'Bookmakers, lottery shops, casinos, gambling venues',
            ],
            [
                'slug' => 'pawn_shop',
                'name' => 'Pawn Shops',
                'indicator_slug' => 'pawn_shop_density',
                'signal' => 'negative',
                'osm_tags' => ['shop' => ['pawnbroker']],
                'catchment_km' => 1.50,
                'description' => 'Pawn shops and pawnbrokers',
            ],
            [
                'slug' => 'fast_food_late',
                'name' => 'Late-Night Fast Food',
                'indicator_slug' => 'fast_food_density',
                'signal' => 'negative',
                'osm_tags' => ['amenity' => ['fast_food']],
                'catchment_km' => 1.00,
                'description' => 'Fast food restaurants',
            ],
            [
                'slug' => 'premium_grocery',
                'name' => 'Premium Grocery',
                'indicator_slug' => 'premium_grocery_density',
                'signal' => 'positive',
                'osm_tags' => null,
                'google_types' => ['supermarket', 'grocery_or_supermarket'],
                'catchment_km' => 2.00,
                'is_active' => false,
                'description' => 'Premium grocery stores (Google Places, future)',
            ],
            [
                'slug' => 'public_transport_stop',
                'name' => 'Public Transport Stops',
                'indicator_slug' => 'transit_stop_density',
                'signal' => 'positive',
                'osm_tags' => ['highway' => ['bus_stop'], 'railway' => ['station', 'halt', 'tram_stop']],
                'catchment_km' => 1.00,
                'description' => 'Bus stops, train stations, tram stops',
            ],
        ];

        foreach ($categories as $data) {
            PoiCategory::query()->updateOrCreate(
                ['slug' => $data['slug']],
                $data
            );
        }
    }
}
