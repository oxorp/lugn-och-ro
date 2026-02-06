<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SentinelArea extends Model
{
    protected $fillable = [
        'deso_code',
        'name',
        'expected_tier',
        'expected_score_min',
        'expected_score_max',
        'rationale',
        'is_active',
    ];

    /**
     * @return array{expected_score_min: 'decimal:2', expected_score_max: 'decimal:2', is_active: 'boolean'}
     */
    protected function casts(): array
    {
        return [
            'expected_score_min' => 'decimal:2',
            'expected_score_max' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
