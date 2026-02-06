<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
