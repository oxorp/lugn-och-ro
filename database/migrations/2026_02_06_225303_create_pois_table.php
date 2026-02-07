<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pois', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 100)->nullable();
            $table->string('source', 40);
            $table->string('category', 60)->index();
            $table->string('subcategory', 60)->nullable();
            $table->string('name', 255)->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('deso_code', 10)->nullable()->index();
            $table->string('municipality_code', 4)->nullable();
            $table->jsonb('tags')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index(['category', 'status']);
            $table->index(['deso_code', 'category']);
        });

        DB::statement("SELECT AddGeometryColumn('public', 'pois', 'geom', 4326, 'POINT', 2)");
        DB::statement('CREATE INDEX pois_geom_idx ON pois USING GIST (geom)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pois');
    }
};
