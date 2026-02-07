<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Tenant extends Model
{
    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'settings',
    ];

    /**
     * @return array{settings: 'array'}
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            if (empty($tenant->uuid)) {
                $tenant->uuid = Str::uuid()->toString();
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function indicatorWeights(): HasMany
    {
        return $this->hasMany(TenantIndicatorWeight::class);
    }

    public function scoreVersions(): HasMany
    {
        return $this->hasMany(ScoreVersion::class);
    }

    /**
     * Get the effective weight for an indicator.
     */
    public function getWeightFor(Indicator $indicator): ?TenantIndicatorWeight
    {
        return $this->indicatorWeights()
            ->where('indicator_id', $indicator->id)
            ->first();
    }

    /**
     * Initialize this tenant's weights from the indicator defaults.
     */
    public function initializeWeights(): void
    {
        $indicators = Indicator::all();
        $now = now();

        $weights = $indicators->map(fn (Indicator $ind) => [
            'tenant_id' => $this->id,
            'indicator_id' => $ind->id,
            'weight' => (float) $ind->weight,
            'direction' => $ind->direction ?? 'neutral',
            'is_active' => (bool) $ind->is_active,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($weights, 1000) as $chunk) {
            TenantIndicatorWeight::insert($chunk);
        }
    }
}
