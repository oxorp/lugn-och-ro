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
        Schema::create('crime_events', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->nullable()->index();
            $table->string('event_type', 40)->index();
            $table->string('severity', 20)->default('standard');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source', 40);
            $table->string('source_url')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('deso_code', 10)->nullable()->index();
            $table->string('municipality_code', 4)->nullable();
            $table->string('municipality_name')->nullable();
            $table->string('location_text')->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamp('reported_at')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_geocoded')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'occurred_at']);
            $table->index(['deso_code', 'occurred_at']);
        });

        DB::statement("SELECT AddGeometryColumn('public', 'crime_events', 'geom', 4326, 'POINT', 2)");
        DB::statement('CREATE INDEX crime_events_geom_idx ON crime_events USING GIST (geom)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crime_events');
    }
};
