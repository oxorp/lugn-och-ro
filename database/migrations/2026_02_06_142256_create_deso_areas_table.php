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
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        Schema::create('deso_areas', function (Blueprint $table) {
            $table->id();
            $table->string('deso_code', 10)->unique()->index();
            $table->string('deso_name')->nullable();
            $table->string('kommun_code', 4)->index();
            $table->string('kommun_name')->nullable();
            $table->string('lan_code', 2)->index();
            $table->string('lan_name')->nullable();
            $table->float('area_km2')->nullable();
            $table->integer('population')->nullable();
            $table->timestamps();
        });

        DB::statement("SELECT AddGeometryColumn('public', 'deso_areas', 'geom', 4326, 'MULTIPOLYGON', 2)");
        DB::statement('CREATE INDEX deso_areas_geom_idx ON deso_areas USING GIST (geom)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deso_areas');
    }
};
