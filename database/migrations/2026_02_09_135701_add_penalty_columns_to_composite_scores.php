<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('composite_scores', function (Blueprint $table) {
            $table->decimal('raw_score_before_penalties', 6, 2)->nullable()->after('score');
            $table->json('penalties_applied')->nullable()->after('top_negative');
        });
    }

    public function down(): void
    {
        Schema::table('composite_scores', function (Blueprint $table) {
            $table->dropColumn(['raw_score_before_penalties', 'penalties_applied']);
        });
    }
};
