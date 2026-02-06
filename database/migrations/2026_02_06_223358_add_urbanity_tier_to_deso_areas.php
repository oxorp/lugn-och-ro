<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('deso_areas', function (Blueprint $table) {
            $table->string('urbanity_tier', 20)->nullable()->index()->after('area_km2');
        });
    }

    public function down(): void
    {
        Schema::table('deso_areas', function (Blueprint $table) {
            $table->dropColumn('urbanity_tier');
        });
    }
};
