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
        Schema::create('deso_boundary_changes', function (Blueprint $table) {
            $table->id();
            $table->string('deso_2018_code', 10)->index();
            $table->string('deso_2025_code', 10)->index();
            $table->string('change_type', 30); // 'unchanged', 'split', 'merged', 'cosmetic', 'recoded', 'new'
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deso_boundary_changes');
    }
};
