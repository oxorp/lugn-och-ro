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
        Schema::create('deso_code_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('old_code', 10)->index();
            $table->string('new_code', 10)->index();
            $table->string('mapping_type', 20); // 'identical', 'recoded', 'cosmetic'
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deso_code_mappings');
    }
};
