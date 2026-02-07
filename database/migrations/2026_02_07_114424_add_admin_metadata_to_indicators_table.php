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
            $table->string('source_api_path')->nullable()->after('source_url');
            $table->string('source_field_code')->nullable()->after('source_api_path');
            $table->text('data_quality_notes')->nullable()->after('source_field_code');
            $table->text('admin_notes')->nullable()->after('data_quality_notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropColumn(['source_api_path', 'source_field_code', 'data_quality_notes', 'admin_notes']);
        });
    }
};
