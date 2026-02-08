<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Geography expression GIST indexes — the existing geometry GIST indexes
        // cannot be used when queries cast geom::geography (which all ST_DWithin
        // distance queries do). This caused full table scans on 137K POIs.
        DB::statement('CREATE INDEX IF NOT EXISTS pois_geog_gist_idx ON pois USING GIST ((geom::geography))');
        DB::statement('CREATE INDEX IF NOT EXISTS schools_geog_gist_idx ON schools USING GIST ((geom::geography))');

        // Composite index for the most common POI query pattern: active status + geography
        DB::statement('CREATE INDEX IF NOT EXISTS pois_active_geog_gist_idx ON pois USING GIST ((geom::geography)) WHERE status = \'active\'');

        // Update show_on_map for Tier 1 categories (things users navigate to)
        DB::table('poi_categories')
            ->whereIn('slug', [
                'school_grundskola',
                'grocery', 'premium_grocery',
                'healthcare',
                'public_transport_stop',
                'gambling', 'pawn_shop', 'sex_shop',
            ])
            ->update(['show_on_map' => true]);

        // Ensure Tier 2 categories are sidebar-only
        DB::table('poi_categories')
            ->whereIn('slug', [
                'restaurant', 'fitness', 'park', 'nature_reserve',
                'pharmacy', 'library', 'cultural_venue', 'bookshop',
                'marina', 'swimming', 'fast_food_late', 'nightclub',
            ])
            ->update(['show_on_map' => false]);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pois_geog_gist_idx');
        DB::statement('DROP INDEX IF EXISTS schools_geog_gist_idx');
        DB::statement('DROP INDEX IF EXISTS pois_active_geog_gist_idx');

        // Reset all show_on_map to false
        DB::table('poi_categories')->update(['show_on_map' => false]);
    }
};
