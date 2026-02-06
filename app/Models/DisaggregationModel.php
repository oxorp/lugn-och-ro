<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisaggregationModel extends Model
{
    protected $fillable = [
        'target_variable',
        'training_year',
        'model_type',
        'r_squared',
        'rmse',
        'coefficients',
        'features_used',
        'kommun_count',
    ];

    /**
     * @return array{r_squared: 'decimal:4', rmse: 'decimal:4', coefficients: 'array', features_used: 'array'}
     */
    protected function casts(): array
    {
        return [
            'r_squared' => 'decimal:4',
            'rmse' => 'decimal:4',
            'coefficients' => 'array',
            'features_used' => 'array',
        ];
    }
}
