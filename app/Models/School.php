<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class School extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolFactory> */
    use HasFactory;

    protected $fillable = [
        'school_unit_code',
        'name',
        'municipality_code',
        'municipality_name',
        'type_of_schooling',
        'school_forms',
        'operator_type',
        'operator_name',
        'status',
        'lat',
        'lng',
        'deso_code',
        'address',
        'postal_code',
        'city',
    ];

    protected function casts(): array
    {
        return [
            'school_forms' => 'array',
        ];
    }

    public function statistics(): HasMany
    {
        return $this->hasMany(SchoolStatistic::class, 'school_unit_code', 'school_unit_code');
    }

    public function latestStatistics(): HasOne
    {
        return $this->hasOne(SchoolStatistic::class, 'school_unit_code', 'school_unit_code')
            ->orderByDesc('academic_year');
    }
}
