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
        Schema::create('ntu_survey_data', function (Blueprint $table) {
            $table->id();
            $table->string('area_code', 20)->index();
            $table->string('area_type', 30);
            $table->string('area_name')->nullable();
            $table->integer('survey_year')->index();
            $table->integer('reference_year')->nullable();
            $table->string('indicator_slug', 80)->index();
            $table->decimal('value', 8, 2)->nullable();
            $table->decimal('confidence_lower', 8, 2)->nullable();
            $table->decimal('confidence_upper', 8, 2)->nullable();
            $table->integer('respondent_count')->nullable();
            $table->string('data_source')->nullable();
            $table->timestamps();

            $table->unique(['area_code', 'area_type', 'survey_year', 'indicator_slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ntu_survey_data');
    }
};
