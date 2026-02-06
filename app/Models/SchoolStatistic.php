<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolStatistic extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolStatisticFactory> */
    use HasFactory;

    protected $fillable = [
        'school_unit_code',
        'academic_year',
        'merit_value_17',
        'merit_value_16',
        'goal_achievement_pct',
        'eligibility_pct',
        'teacher_certification_pct',
        'student_count',
        'data_source',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_unit_code', 'school_unit_code');
    }
}
