<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngestionLog extends Model
{
    protected $fillable = [
        'source',
        'command',
        'status',
        'trigger',
        'triggered_by',
        'records_processed',
        'records_created',
        'records_updated',
        'records_failed',
        'records_skipped',
        'error_message',
        'summary',
        'warnings',
        'stats',
        'duration_seconds',
        'memory_peak_mb',
        'metadata',
        'started_at',
        'completed_at',
    ];

    public function validationResults(): HasMany
    {
        return $this->hasMany(ValidationResult::class);
    }

    /**
     * @return array{metadata: 'array', warnings: 'array', stats: 'array', started_at: 'datetime', completed_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'warnings' => 'array',
            'stats' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
