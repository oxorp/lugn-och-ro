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
        Schema::create('indicator_values', function (Blueprint $table) {
            $table->id();
            $table->string('deso_code', 10)->index();
            $table->foreignId('indicator_id')->constrained()->index();
            $table->integer('year')->index();
            $table->decimal('raw_value', 14, 4)->nullable();
            $table->decimal('normalized_value', 8, 6)->nullable();
            $table->timestamps();

            $table->unique(['deso_code', 'indicator_id', 'year']);
            $table->index(['indicator_id', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('indicator_values');
    }
};
