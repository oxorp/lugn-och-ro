<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScorePenalty extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'category',
        'penalty_type',
        'penalty_value',
        'is_active',
        'applies_to',
        'display_order',
        'color',
        'border_color',
        'opacity',
        'metadata',
    ];

    /**
     * @return array{is_active: 'boolean', penalty_value: 'decimal:2', opacity: 'decimal:2', metadata: 'array'}
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'penalty_value' => 'decimal:2',
            'opacity' => 'decimal:2',
            'metadata' => 'array',
        ];
    }
}
