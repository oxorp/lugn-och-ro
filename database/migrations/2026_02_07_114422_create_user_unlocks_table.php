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
        Schema::create('user_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('unlock_type', 20);
            $table->string('unlock_code', 10);
            $table->string('payment_reference')->nullable();
            $table->integer('price_paid')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'unlock_type', 'unlock_code']);
            $table->index(['user_id', 'unlock_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_unlocks');
    }
};
