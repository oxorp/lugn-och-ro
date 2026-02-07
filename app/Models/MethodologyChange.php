<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MethodologyChange extends Model
{
    protected $fillable = [
        'source',
        'indicator_id',
        'year_affected',
        'change_type',
        'description',
        'breaks_trend',
        'source_url',
    ];

    protected function casts(): array
    {
        return [
            'breaks_trend' => 'boolean',
        ];
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }
}
