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
        Schema::create('methodology_changes', function (Blueprint $table) {
            $table->id();
            $table->string('source', 40);
            $table->foreignId('indicator_id')->nullable()->constrained();
            $table->integer('year_affected');
            $table->string('change_type', 30); // 'definition_change', 'calculation_change', 'base_year_change'
            $table->text('description');
            $table->boolean('breaks_trend')->default(false);
            $table->string('source_url')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('methodology_changes');
    }
};
