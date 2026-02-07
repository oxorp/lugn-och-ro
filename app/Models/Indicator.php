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
        'description_short',
        'description_long',
        'methodology_note',
        'national_context',
        'data_vintage',
        'source',
        'source_name',
        'source_url',
        'source_api_path',
        'source_field_code',
        'data_quality_notes',
        'admin_notes',
        'update_frequency',
        'source_table',
        'unit',
        'direction',
        'weight',
        'normalization',
        'normalization_scope',
        'is_active',
        'display_order',
        'category',
        'latest_data_date',
        'last_ingested_at',
        'last_validated_at',
        'freshness_status',
    ];

    /**
     * @return array{is_active: 'boolean', weight: 'decimal:4', latest_data_date: 'date', last_ingested_at: 'datetime', last_validated_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'weight' => 'decimal:4',
            'latest_data_date' => 'date',
            'last_ingested_at' => 'datetime',
            'last_validated_at' => 'datetime',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(IndicatorValue::class);
    }
}
