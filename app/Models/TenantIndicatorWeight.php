<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantIndicatorWeight extends Model
{
    protected $fillable = [
        'tenant_id',
        'indicator_id',
        'weight',
        'direction',
        'is_active',
    ];

    /**
     * @return array{weight: 'decimal:4', is_active: 'boolean'}
     */
    protected function casts(): array
    {
        return [
            'weight' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }
}
