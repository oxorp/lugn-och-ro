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
            $table->boolean('trend_eligible')->default(true)->after('urbanity_tier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deso_areas', function (Blueprint $table) {
            $table->dropColumn('trend_eligible');
        });
    }
};
