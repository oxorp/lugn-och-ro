<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KronofogdenStatistic extends Model
{
    protected $fillable = [
        'municipality_code',
        'municipality_name',
        'county_code',
        'county_name',
        'year',
        'indebted_total',
        'indebted_men',
        'indebted_women',
        'indebted_pct',
        'indebted_men_pct',
        'indebted_women_pct',
        'median_debt_sek',
        'total_debt_sek',
        'eviction_applications',
        'evictions_executed',
        'evictions_children',
        'eviction_rate_per_100k',
        'payment_order_count',
        'payment_order_persons',
        'payment_order_amount_msek',
        'debt_restructuring_applications',
        'debt_restructuring_granted',
        'debt_restructuring_ongoing',
        'adult_population',
        'data_source',
    ];

    /**
     * @return array{indebted_pct: 'decimal:2', median_debt_sek: 'decimal:0', total_debt_sek: 'decimal:0', payment_order_amount_msek: 'decimal:1'}
     */
    protected function casts(): array
    {
        return [
            'indebted_pct' => 'decimal:2',
            'indebted_men_pct' => 'decimal:2',
            'indebted_women_pct' => 'decimal:2',
            'median_debt_sek' => 'decimal:0',
            'total_debt_sek' => 'decimal:0',
            'eviction_rate_per_100k' => 'decimal:2',
            'payment_order_amount_msek' => 'decimal:1',
        ];
    }
}
