<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PoiCategory extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'indicator_slug',
        'signal',
        'osm_tags',
        'google_types',
        'catchment_km',
        'is_active',
        'description',
    ];

    /**
     * @return array{osm_tags: 'array', google_types: 'array', is_active: 'boolean', catchment_km: 'decimal:2'}
     */
    protected function casts(): array
    {
        return [
            'osm_tags' => 'array',
            'google_types' => 'array',
            'is_active' => 'boolean',
            'catchment_km' => 'decimal:2',
        ];
    }
}
