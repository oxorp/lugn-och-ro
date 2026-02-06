<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_statistics', function (Blueprint $table) {
            $table->id();
            $table->string('school_unit_code', 20)->index();
            $table->string('academic_year', 10);
            $table->decimal('merit_value_17', 6, 1)->nullable();
            $table->decimal('merit_value_16', 6, 1)->nullable();
            $table->decimal('goal_achievement_pct', 5, 1)->nullable();
            $table->decimal('eligibility_pct', 5, 1)->nullable();
            $table->decimal('teacher_certification_pct', 5, 1)->nullable();
            $table->integer('student_count')->nullable();
            $table->string('data_source')->nullable();
            $table->timestamps();

            $table->unique(['school_unit_code', 'academic_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_statistics');
    }
};
