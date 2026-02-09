<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_penalties', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique()->index();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('category', 40);
            $table->string('penalty_type', 20)->default('absolute');
            $table->decimal('penalty_value', 6, 2);
            $table->boolean('is_active')->default(true);
            $table->string('applies_to', 40)->default('composite_score');
            $table->integer('display_order')->default(0);
            $table->string('color', 7)->nullable();
            $table->string('border_color', 7)->nullable();
            $table->decimal('opacity', 3, 2)->default(0.15);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_penalties');
    }
};
