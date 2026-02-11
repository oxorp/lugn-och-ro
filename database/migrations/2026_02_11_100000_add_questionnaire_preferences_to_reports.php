<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            // User questionnaire preferences
            // Structure: {
            //   "priorities": ["schools", "safety", "green_areas"],
            //   "walking_distance_minutes": 15,
            //   "has_car": true|false|null
            // }
            $table->json('preferences')->nullable()->after('priorities');

            // Reachability ring configuration based on preferences + urbanity
            // Structure: [
            //   {"ring": 1, "minutes": 5, "mode": "pedestrian", "label": "Nåbart inom 5 min promenad"},
            //   {"ring": 2, "minutes": 15, "mode": "pedestrian", "label": "Nåbart inom 15 min promenad"},
            //   {"ring": 3, "minutes": 10, "mode": "auto", "label": "Nåbart inom 10 min bil"}
            // ]
            $table->json('reachability_rings')->nullable()->after('isochrone');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['preferences', 'reachability_rings']);
        });
    }
};
