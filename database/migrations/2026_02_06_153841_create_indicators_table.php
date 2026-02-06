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
        Schema::create('indicators', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('source', 40);
            $table->string('source_table')->nullable();
            $table->string('unit', 40)->default('number');
            $table->enum('direction', ['positive', 'negative', 'neutral'])->default('neutral');
            $table->decimal('weight', 5, 4)->default(0.0);
            $table->string('normalization', 40)->default('rank_percentile');
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->string('category')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('indicators');
    }
};
