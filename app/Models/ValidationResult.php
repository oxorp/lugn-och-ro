<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValidationResult extends Model
{
    protected $fillable = [
        'ingestion_log_id',
        'validation_rule_id',
        'status',
        'details',
        'affected_count',
        'message',
    ];

    /**
     * @return array{details: 'array'}
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    public function ingestionLog(): BelongsTo
    {
        return $this->belongsTo(IngestionLog::class);
    }

    public function validationRule(): BelongsTo
    {
        return $this->belongsTo(ValidationRule::class);
    }
}
