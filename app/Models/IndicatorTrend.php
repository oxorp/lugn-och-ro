<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndicatorTrend extends Model
{
    protected $fillable = [
        'deso_code',
        'indicator_id',
        'base_year',
        'end_year',
        'data_points',
        'absolute_change',
        'percent_change',
        'direction',
        'confidence',
    ];

    protected function casts(): array
    {
        return [
            'absolute_change' => 'decimal:4',
            'percent_change' => 'decimal:2',
            'confidence' => 'decimal:2',
        ];
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }
}
