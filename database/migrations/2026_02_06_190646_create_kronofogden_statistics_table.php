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
        Schema::create('kronofogden_statistics', function (Blueprint $table) {
            $table->id();
            $table->string('municipality_code', 4)->index();
            $table->string('municipality_name')->nullable();
            $table->string('county_code', 2)->nullable()->index();
            $table->string('county_name')->nullable();
            $table->integer('year')->index();

            // Skuldsatta privatpersoner
            $table->integer('indebted_total')->nullable();
            $table->integer('indebted_men')->nullable();
            $table->integer('indebted_women')->nullable();
            $table->decimal('indebted_pct', 5, 2)->nullable();
            $table->decimal('indebted_men_pct', 5, 2)->nullable();
            $table->decimal('indebted_women_pct', 5, 2)->nullable();
            $table->decimal('median_debt_sek', 12, 0)->nullable();
            $table->decimal('total_debt_sek', 14, 0)->nullable();

            // Vräkningar (evictions)
            $table->integer('eviction_applications')->nullable();
            $table->integer('evictions_executed')->nullable();
            $table->integer('evictions_children')->nullable();

            // Betalningsföreläggande (payment orders)
            $table->integer('payment_order_count')->nullable();
            $table->integer('payment_order_persons')->nullable();
            $table->decimal('payment_order_amount_msek', 10, 1)->nullable();

            // Skuldsanering (debt restructuring)
            $table->integer('debt_restructuring_applications')->nullable();
            $table->integer('debt_restructuring_granted')->nullable();
            $table->integer('debt_restructuring_ongoing')->nullable();

            // Population reference
            $table->integer('adult_population')->nullable();

            $table->string('data_source')->nullable();
            $table->timestamps();

            $table->unique(['municipality_code', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kronofogden_statistics');
    }
};
