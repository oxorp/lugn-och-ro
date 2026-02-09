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
        Schema::create('deso_crosswalk', function (Blueprint $table) {
            $table->id();
            $table->string('old_code', 10)->index();
            $table->string('new_code', 10)->index();
            $table->decimal('overlap_fraction', 8, 6);
            $table->decimal('reverse_fraction', 8, 6);
            $table->string('mapping_type', 20);
            $table->timestamps();

            $table->unique(['old_code', 'new_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deso_crosswalk');
    }
};
