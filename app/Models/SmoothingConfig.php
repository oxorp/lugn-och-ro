<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmoothingConfig extends Model
{
    protected $fillable = [
        'name',
        'self_weight',
        'neighbor_weight',
        'k_rings',
        'decay_function',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'self_weight' => 'decimal:3',
            'neighbor_weight' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }
}
