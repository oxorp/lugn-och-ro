<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('poi_categories', function (Blueprint $table) {
            $table->boolean('show_on_map')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('poi_categories', function (Blueprint $table) {
            $table->dropColumn('show_on_map');
        });
    }
};
