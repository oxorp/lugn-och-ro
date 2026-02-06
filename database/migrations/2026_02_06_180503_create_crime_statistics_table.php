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
        Schema::create('crime_statistics', function (Blueprint $table) {
            $table->id();
            $table->string('municipality_code', 4)->index();
            $table->string('municipality_name')->nullable();
            $table->integer('year')->index();
            $table->string('crime_category', 80)->index();
            $table->integer('reported_count')->nullable();
            $table->decimal('rate_per_100k', 10, 2)->nullable();
            $table->integer('population')->nullable();
            $table->string('data_source')->nullable();
            $table->timestamps();

            $table->unique(['municipality_code', 'year', 'crime_category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crime_statistics');
    }
};
