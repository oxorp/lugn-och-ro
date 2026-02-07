<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Poi extends Model
{
    /** @use HasFactory<\Database\Factories\PoiFactory> */
    use HasFactory;

    protected $fillable = [
        'external_id',
        'source',
        'category',
        'subcategory',
        'name',
        'lat',
        'lng',
        'deso_code',
        'municipality_code',
        'tags',
        'metadata',
        'status',
        'last_verified_at',
    ];

    /**
     * @return array{tags: 'array', metadata: 'array', last_verified_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'metadata' => 'array',
            'last_verified_at' => 'datetime',
        ];
    }
}
