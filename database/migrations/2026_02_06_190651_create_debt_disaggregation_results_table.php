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
        Schema::create('debt_disaggregation_results', function (Blueprint $table) {
            $table->id();
            $table->string('deso_code', 10)->index();
            $table->integer('year')->index();
            $table->string('municipality_code', 4)->index();

            // Estimated values
            $table->decimal('estimated_debt_rate', 6, 3)->nullable();
            $table->decimal('estimated_eviction_rate', 8, 4)->nullable();
            $table->decimal('estimated_payment_order_rate', 8, 4)->nullable();

            // Confidence / quality
            $table->decimal('propensity_weight', 8, 6)->nullable();
            $table->boolean('is_constrained')->default(true);

            $table->string('model_version')->nullable();
            $table->timestamps();

            $table->unique(['deso_code', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('debt_disaggregation_results');
    }
};
