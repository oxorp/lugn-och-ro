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
        Schema::create('indicator_trends', function (Blueprint $table) {
            $table->id();
            $table->string('deso_code', 10)->index();
            $table->foreignId('indicator_id')->constrained()->index();
            $table->integer('base_year');
            $table->integer('end_year');
            $table->integer('data_points');
            $table->decimal('absolute_change', 14, 4)->nullable();
            $table->decimal('percent_change', 8, 2)->nullable();
            $table->string('direction', 15); // 'rising', 'falling', 'stable', 'insufficient'
            $table->decimal('confidence', 3, 2)->nullable();
            $table->timestamps();

            $table->unique(['deso_code', 'indicator_id', 'base_year', 'end_year'], 'indicator_trends_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('indicator_trends');
    }
};
