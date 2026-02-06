<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrimeStatistic extends Model
{
    protected $fillable = [
        'municipality_code',
        'municipality_name',
        'year',
        'crime_category',
        'reported_count',
        'rate_per_100k',
        'population',
        'data_source',
    ];

    /**
     * @return array{rate_per_100k: 'decimal:2'}
     */
    protected function casts(): array
    {
        return [
            'rate_per_100k' => 'decimal:2',
        ];
    }
}
