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
        Schema::create('composite_scores', function (Blueprint $table) {
            $table->id();
            $table->string('deso_code', 10)->index();
            $table->integer('year');
            $table->decimal('score', 6, 2);
            $table->decimal('trend_1y', 6, 2)->nullable();
            $table->decimal('trend_3y', 6, 2)->nullable();
            $table->json('factor_scores')->nullable();
            $table->json('top_positive')->nullable();
            $table->json('top_negative')->nullable();
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['deso_code', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('composite_scores');
    }
};
