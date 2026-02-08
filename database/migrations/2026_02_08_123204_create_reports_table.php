<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('guest_email')->nullable()->index();

            // Location snapshot
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('address')->nullable();
            $table->string('kommun_name')->nullable();
            $table->string('lan_name')->nullable();
            $table->string('deso_code', 10)->nullable()->index();

            // Score snapshot
            $table->decimal('score', 6, 2)->nullable();
            $table->string('score_label')->nullable();

            // Payment
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->integer('amount_ore');
            $table->string('currency', 3)->default('sek');
            $table->string('status', 20)->default('pending');

            $table->integer('view_count')->default(0);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
