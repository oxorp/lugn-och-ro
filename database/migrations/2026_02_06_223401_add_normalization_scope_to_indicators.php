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
        Schema::table('indicators', function (Blueprint $table) {
            $table->string('normalization_scope', 30)->default('national')->after('normalization');
        });
    }

    public function down(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropColumn('normalization_scope');
        });
    }
};
