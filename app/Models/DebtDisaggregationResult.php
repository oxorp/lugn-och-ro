<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DebtDisaggregationResult extends Model
{
    protected $fillable = [
        'deso_code',
        'year',
        'municipality_code',
        'estimated_debt_rate',
        'estimated_eviction_rate',
        'estimated_payment_order_rate',
        'propensity_weight',
        'is_constrained',
        'model_version',
    ];

    /**
     * @return array{estimated_debt_rate: 'decimal:3', estimated_eviction_rate: 'decimal:4', estimated_payment_order_rate: 'decimal:4', propensity_weight: 'decimal:6', is_constrained: 'boolean'}
     */
    protected function casts(): array
    {
        return [
            'estimated_debt_rate' => 'decimal:3',
            'estimated_eviction_rate' => 'decimal:4',
            'estimated_payment_order_rate' => 'decimal:4',
            'propensity_weight' => 'decimal:6',
            'is_constrained' => 'boolean',
        ];
    }
}
