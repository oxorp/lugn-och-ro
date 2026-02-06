<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->date('latest_data_date')->nullable()->after('category');
            $table->timestamp('last_ingested_at')->nullable()->after('latest_data_date');
            $table->timestamp('last_validated_at')->nullable()->after('last_ingested_at');
            $table->string('freshness_status', 20)->default('unknown')->after('last_validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropColumn(['latest_data_date', 'last_ingested_at', 'last_validated_at', 'freshness_status']);
        });
    }
};
