<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DesoArea extends Model
{
    /** @use HasFactory<\Database\Factories\DesoAreaFactory> */
    use HasFactory;

    protected $fillable = [
        'deso_code',
        'deso_name',
        'kommun_code',
        'kommun_name',
        'lan_code',
        'lan_name',
        'area_km2',
        'population',
        'urbanity_tier',
        'trend_eligible',
    ];

    protected function casts(): array
    {
        return [
            'trend_eligible' => 'boolean',
        ];
    }
}
