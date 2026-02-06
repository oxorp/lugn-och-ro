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
        Schema::create('disaggregation_models', function (Blueprint $table) {
            $table->id();
            $table->string('target_variable');
            $table->integer('training_year');
            $table->string('model_type')->default('weighted_regression');
            $table->decimal('r_squared', 6, 4)->nullable();
            $table->decimal('rmse', 10, 4)->nullable();
            $table->json('coefficients')->nullable();
            $table->json('features_used')->nullable();
            $table->integer('kommun_count')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disaggregation_models');
    }
};
