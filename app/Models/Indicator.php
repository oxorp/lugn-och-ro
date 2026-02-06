<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Indicator extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'source',
        'source_table',
        'unit',
        'direction',
        'weight',
        'normalization',
        'is_active',
        'display_order',
        'category',
    ];

    /**
     * @return array{is_active: 'boolean', weight: 'decimal:4'}
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'weight' => 'decimal:4',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(IndicatorValue::class);
    }
}
