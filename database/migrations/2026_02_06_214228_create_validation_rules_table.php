<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicator_id')->nullable()->constrained();
            $table->string('source', 40)->nullable();
            $table->string('rule_type', 40);
            $table->string('name');
            $table->string('severity', 20)->default('warning');
            $table->json('parameters');
            $table->boolean('is_active')->default(true);
            $table->boolean('blocks_scoring')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_rules');
    }
};
