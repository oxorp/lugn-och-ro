<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            // Full snapshot data
            $table->json('area_indicators')->nullable();
            $table->json('proximity_factors')->nullable();
            $table->json('schools')->nullable();
            $table->json('category_verdicts')->nullable();
            $table->json('score_history')->nullable();
            $table->json('deso_meta')->nullable();
            $table->json('national_references')->nullable();
            $table->json('map_snapshot')->nullable();
            $table->json('outlook')->nullable();
            $table->json('top_positive')->nullable();
            $table->json('top_negative')->nullable();
            $table->json('priorities')->nullable();

            // Score details
            $table->decimal('default_score', 6, 2)->nullable();
            $table->decimal('personalized_score', 6, 2)->nullable();
            $table->decimal('trend_1y', 6, 2)->nullable();

            // Metadata
            $table->string('model_version', 20)->nullable();
            $table->integer('indicator_count')->default(0);
            $table->integer('year')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn([
                'area_indicators',
                'proximity_factors',
                'schools',
                'category_verdicts',
                'score_history',
                'deso_meta',
                'national_references',
                'map_snapshot',
                'outlook',
                'top_positive',
                'top_negative',
                'priorities',
                'default_score',
                'personalized_score',
                'trend_1y',
                'model_version',
                'indicator_count',
                'year',
            ]);
        });
    }
};
