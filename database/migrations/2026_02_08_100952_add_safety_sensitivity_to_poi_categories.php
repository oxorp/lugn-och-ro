<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('poi_categories', function (Blueprint $table) {
            $table->decimal('safety_sensitivity', 4, 2)->default(1.0)->after('signal');
        });
    }

    public function down(): void
    {
        Schema::table('poi_categories', function (Blueprint $table) {
            $table->dropColumn('safety_sensitivity');
        });
    }
};
