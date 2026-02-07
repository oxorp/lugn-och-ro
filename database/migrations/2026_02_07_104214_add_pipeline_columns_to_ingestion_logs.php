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
        Schema::table('ingestion_logs', function (Blueprint $table) {
            $table->string('trigger', 20)->default('manual')->after('status');
            $table->string('triggered_by')->nullable()->after('trigger');
            $table->integer('records_failed')->default(0)->after('records_updated');
            $table->integer('records_skipped')->default(0)->after('records_failed');
            $table->text('summary')->nullable()->after('error_message');
            $table->json('warnings')->nullable()->after('summary');
            $table->json('stats')->nullable()->after('warnings');
            $table->integer('duration_seconds')->nullable()->after('stats');
            $table->decimal('memory_peak_mb', 8, 2)->nullable()->after('duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('ingestion_logs', function (Blueprint $table) {
            $table->dropColumn([
                'trigger',
                'triggered_by',
                'records_failed',
                'records_skipped',
                'summary',
                'warnings',
                'stats',
                'duration_seconds',
                'memory_peak_mb',
            ]);
        });
    }
};
