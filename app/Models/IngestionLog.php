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
        'records_processed',
        'records_created',
        'records_updated',
        'error_message',
        'metadata',
        'started_at',
        'completed_at',
    ];

    public function validationResults(): HasMany
    {
        return $this->hasMany(ValidationResult::class);
    }

    /**
     * @return array{metadata: 'array', started_at: 'datetime', completed_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
