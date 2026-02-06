<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class H3Score extends Model
{
    protected $table = 'h3_scores';

    protected $fillable = [
        'h3_index',
        'year',
        'resolution',
        'score_raw',
        'score_smoothed',
        'smoothing_factor',
        'trend_1y',
        'factor_scores',
        'primary_deso_code',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'score_raw' => 'decimal:2',
            'score_smoothed' => 'decimal:2',
            'smoothing_factor' => 'decimal:3',
            'trend_1y' => 'decimal:2',
            'factor_scores' => 'array',
            'computed_at' => 'datetime',
        ];
    }
}
