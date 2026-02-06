<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndicatorValue extends Model
{
    protected $fillable = [
        'deso_code',
        'indicator_id',
        'year',
        'raw_value',
        'normalized_value',
    ];

    /**
     * @return array{raw_value: 'decimal:4', normalized_value: 'decimal:6'}
     */
    protected function casts(): array
    {
        return [
            'raw_value' => 'decimal:4',
            'normalized_value' => 'decimal:6',
        ];
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }
}
