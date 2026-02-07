<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add display columns to pois table
        Schema::table('pois', function (Blueprint $table) {
            $table->string('poi_type', 60)->nullable()->after('subcategory');
            $table->unsignedTinyInteger('display_tier')->default(4)->after('poi_type');
            $table->string('sentiment', 10)->default('neutral')->after('display_tier');
        });

        // Add display columns to poi_categories table
        Schema::table('poi_categories', function (Blueprint $table) {
            $table->unsignedTinyInteger('display_tier')->default(4)->after('catchment_km');
            $table->string('icon', 40)->default('map-pin')->after('display_tier');
            $table->string('color', 10)->default('#6b7280')->after('icon');
            $table->float('impact_radius_km')->nullable()->after('color');
            $table->string('category_group', 40)->nullable()->after('impact_radius_km');
        });

        // Index for viewport queries: tier + spatial
        DB::statement('CREATE INDEX pois_tier_geom_idx ON pois USING GIST (geom) WHERE display_tier <= 5');
        DB::statement('CREATE INDEX pois_display_tier_idx ON pois (display_tier)');
        DB::statement('CREATE INDEX pois_sentiment_idx ON pois (sentiment)');

        // Backfill poi_type from category for existing POIs
        DB::statement('UPDATE pois SET poi_type = category WHERE poi_type IS NULL');

        // Backfill sentiment from poi_categories
        DB::statement("
            UPDATE pois SET sentiment = pc.signal
            FROM poi_categories pc
            WHERE pois.category = pc.slug
              AND pois.sentiment = 'neutral'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pois_tier_geom_idx');
        DB::statement('DROP INDEX IF EXISTS pois_display_tier_idx');
        DB::statement('DROP INDEX IF EXISTS pois_sentiment_idx');

        Schema::table('poi_categories', function (Blueprint $table) {
            $table->dropColumn(['display_tier', 'icon', 'color', 'impact_radius_km', 'category_group']);
        });

        Schema::table('pois', function (Blueprint $table) {
            $table->dropColumn(['poi_type', 'display_tier', 'sentiment']);
        });
    }
};
