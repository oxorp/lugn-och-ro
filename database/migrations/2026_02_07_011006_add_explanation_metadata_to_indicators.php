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
        Schema::table('indicators', function (Blueprint $table) {
            $table->text('description_short')->nullable()->after('description');
            $table->text('description_long')->nullable()->after('description_short');
            $table->text('methodology_note')->nullable()->after('description_long');
            $table->string('national_context')->nullable()->after('methodology_note');
            $table->string('data_vintage')->nullable()->after('national_context');
            $table->string('source_name')->nullable()->after('source');
            $table->string('source_url')->nullable()->after('source_name');
            $table->string('update_frequency')->nullable()->after('source_url');
        });
    }

    public function down(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropColumn([
                'description_short',
                'description_long',
                'methodology_note',
                'national_context',
                'data_vintage',
                'source_name',
                'source_url',
                'update_frequency',
            ]);
        });
    }
};
