<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_versions', function (Blueprint $table) {
            $table->id();
            $table->integer('year');
            $table->string('status', 20)->default('pending');
            $table->json('indicators_used')->nullable();
            $table->json('ingestion_log_ids')->nullable();
            $table->json('validation_summary')->nullable();
            $table->json('sentinel_results')->nullable();
            $table->integer('deso_count')->default(0);
            $table->decimal('mean_score', 6, 2)->nullable();
            $table->decimal('stddev_score', 6, 2)->nullable();
            $table->string('computed_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('computed_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['year', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_versions');
    }
};
