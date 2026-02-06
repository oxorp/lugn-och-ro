<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Enable H3 extensions (h3-pg)
        DB::statement('CREATE EXTENSION IF NOT EXISTS h3');
        DB::statement('CREATE EXTENSION IF NOT EXISTS h3_postgis CASCADE');

        // DeSO-to-H3 mapping table (pre-computed once)
        Schema::create('deso_h3_mapping', function (Blueprint $table) {
            $table->id();
            $table->string('deso_code', 10)->index();
            $table->string('h3_index', 16)->index();
            $table->decimal('area_weight', 8, 6)->default(1.0);
            $table->integer('resolution')->default(8);
            $table->timestamps();

            $table->unique(['deso_code', 'h3_index']);
            $table->index(['h3_index', 'deso_code']);
        });

        // H3 scores table (projected from DeSO composite scores)
        Schema::create('h3_scores', function (Blueprint $table) {
            $table->id();
            $table->string('h3_index', 16)->index();
            $table->integer('year');
            $table->integer('resolution')->default(8);
            $table->decimal('score_raw', 6, 2)->nullable();
            $table->decimal('score_smoothed', 6, 2)->nullable();
            $table->decimal('smoothing_factor', 4, 3)->default(0.300);
            $table->decimal('trend_1y', 6, 2)->nullable();
            $table->json('factor_scores')->nullable();
            $table->string('primary_deso_code', 10)->nullable()->index();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['h3_index', 'year', 'resolution']);
        });

        // Smoothing configuration presets
        Schema::create('smoothing_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('self_weight', 4, 3);
            $table->decimal('neighbor_weight', 4, 3);
            $table->integer('k_rings')->default(1);
            $table->string('decay_function', 20)->default('linear');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        // Seed default smoothing configs
        DB::table('smoothing_configs')->insert([
            [
                'name' => 'None',
                'self_weight' => 1.000,
                'neighbor_weight' => 0.000,
                'k_rings' => 0,
                'decay_function' => 'linear',
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Light',
                'self_weight' => 0.700,
                'neighbor_weight' => 0.300,
                'k_rings' => 1,
                'decay_function' => 'linear',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Medium',
                'self_weight' => 0.600,
                'neighbor_weight' => 0.400,
                'k_rings' => 1,
                'decay_function' => 'linear',
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Strong',
                'self_weight' => 0.500,
                'neighbor_weight' => 0.500,
                'k_rings' => 2,
                'decay_function' => 'gaussian',
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smoothing_configs');
        Schema::dropIfExists('h3_scores');
        Schema::dropIfExists('deso_h3_mapping');

        DB::statement('DROP EXTENSION IF EXISTS h3_postgis');
        DB::statement('DROP EXTENSION IF EXISTS h3');
    }
};
