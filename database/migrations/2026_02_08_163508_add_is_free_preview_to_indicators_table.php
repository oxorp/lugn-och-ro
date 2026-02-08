<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->boolean('is_free_preview')->default(false)->after('is_active');
        });

        // Set free preview indicators (2 per display category)
        $freePreviewSlugs = [
            'perceived_safety',
            'crime_violent_rate',
            'median_income',
            'employment_rate',
            'school_merit_value_avg',
            'school_teacher_certification_avg',
            'prox_transit',
            'prox_grocery',
        ];

        DB::table('indicators')
            ->whereIn('slug', $freePreviewSlugs)
            ->update(['is_free_preview' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropColumn('is_free_preview');
        });
    }
};
