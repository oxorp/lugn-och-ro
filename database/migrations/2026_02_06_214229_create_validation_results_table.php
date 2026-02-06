<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingestion_log_id')->constrained();
            $table->foreignId('validation_rule_id')->constrained();
            $table->string('status', 20);
            $table->json('details')->nullable();
            $table->integer('affected_count')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['ingestion_log_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_results');
    }
};
