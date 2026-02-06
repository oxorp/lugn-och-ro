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
        Schema::table('kronofogden_statistics', function (Blueprint $table) {
            $table->decimal('eviction_rate_per_100k', 8, 2)->nullable()->after('evictions_children');
        });
    }

    public function down(): void
    {
        Schema::table('kronofogden_statistics', function (Blueprint $table) {
            $table->dropColumn('eviction_rate_per_100k');
        });
    }
};
