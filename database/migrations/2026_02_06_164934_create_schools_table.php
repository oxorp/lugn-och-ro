<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('school_unit_code', 20)->unique()->index();
            $table->string('name');
            $table->string('municipality_code', 4)->nullable()->index();
            $table->string('municipality_name')->nullable();
            $table->string('type_of_schooling')->nullable();
            $table->string('operator_type')->nullable();
            $table->string('operator_name')->nullable();
            $table->string('status')->default('active');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('deso_code', 10)->nullable()->index();
            $table->string('address')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('city')->nullable();
            $table->timestamps();
        });

        DB::statement("SELECT AddGeometryColumn('public', 'schools', 'geom', 4326, 'POINT', 2)");
        DB::statement('CREATE INDEX schools_geom_idx ON schools USING GIST (geom)');
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
