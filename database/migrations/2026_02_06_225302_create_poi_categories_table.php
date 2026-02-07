<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poi_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name');
            $table->string('indicator_slug', 80)->nullable();
            $table->enum('signal', ['positive', 'negative', 'neutral'])->default('neutral');
            $table->json('osm_tags')->nullable();
            $table->json('google_types')->nullable();
            $table->decimal('catchment_km', 5, 2)->default(1.50);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poi_categories');
    }
};
