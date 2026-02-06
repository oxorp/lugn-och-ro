<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ValidationRule extends Model
{
    protected $fillable = [
        'indicator_id',
        'source',
        'rule_type',
        'name',
        'severity',
        'parameters',
        'is_active',
        'blocks_scoring',
        'description',
    ];

    /**
     * @return array{parameters: 'array', is_active: 'boolean', blocks_scoring: 'boolean'}
     */
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'is_active' => 'boolean',
            'blocks_scoring' => 'boolean',
        ];
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ValidationResult::class);
    }
}
