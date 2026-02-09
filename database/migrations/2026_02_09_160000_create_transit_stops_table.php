<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transit_stops', function (Blueprint $table) {
            $table->id();
            $table->string('gtfs_stop_id', 30)->index();
            $table->string('name', 255)->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('parent_station', 30)->nullable();
            $table->unsignedTinyInteger('location_type')->default(0);
            $table->string('source', 20)->default('gtfs')->index();
            $table->string('stop_type', 20)->nullable();
            $table->integer('weekly_departures')->nullable();
            $table->integer('routes_count')->nullable();
            $table->string('deso_code', 10)->nullable()->index();
            $table->timestamps();

            $table->unique(['source', 'gtfs_stop_id']);
        });

        DB::statement("SELECT AddGeometryColumn('public', 'transit_stops', 'geom', 4326, 'POINT', 2)");
        DB::statement('CREATE INDEX transit_stops_geom_idx ON transit_stops USING GIST (geom)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transit_stops');
    }
};
