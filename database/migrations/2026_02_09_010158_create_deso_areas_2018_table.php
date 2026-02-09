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
        Schema::create('deso_areas_2018', function (Blueprint $table) {
            $table->id();
            $table->string('deso_code', 10)->unique()->index();
            $table->string('deso_name')->nullable();
            $table->string('kommun_code', 4)->nullable();
            $table->string('kommun_name')->nullable();
            $table->timestamps();
        });

        DB::statement("SELECT AddGeometryColumn('public', 'deso_areas_2018', 'geom', 4326, 'MULTIPOLYGON', 2)");
        DB::statement('CREATE INDEX deso_areas_2018_geom_idx ON deso_areas_2018 USING GIST (geom)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deso_areas_2018');
    }
};
