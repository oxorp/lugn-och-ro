<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrimeEvent extends Model
{
    protected $fillable = [
        'external_id',
        'event_type',
        'severity',
        'title',
        'description',
        'source',
        'source_url',
        'lat',
        'lng',
        'deso_code',
        'municipality_code',
        'municipality_name',
        'location_text',
        'occurred_at',
        'reported_at',
        'is_verified',
        'is_geocoded',
        'metadata',
    ];

    /**
     * @return array{occurred_at: 'datetime', reported_at: 'datetime', is_verified: 'boolean', is_geocoded: 'boolean', metadata: 'array'}
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'reported_at' => 'datetime',
            'is_verified' => 'boolean',
            'is_geocoded' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
