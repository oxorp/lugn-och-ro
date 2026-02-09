<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transit_stop_frequencies', function (Blueprint $table) {
            $table->id();
            $table->string('gtfs_stop_id', 30)->index();
            $table->string('mode_category', 20);
            $table->integer('departures_06_09')->default(0);
            $table->integer('departures_09_15')->default(0);
            $table->integer('departures_15_18')->default(0);
            $table->integer('departures_18_22')->default(0);
            $table->integer('departures_06_20_total')->default(0);
            $table->integer('distinct_routes')->default(0);
            $table->string('day_type', 10)->default('weekday');
            $table->string('feed_version', 20)->nullable();
            $table->timestamps();

            $table->index(['gtfs_stop_id', 'day_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transit_stop_frequencies');
    }
};
