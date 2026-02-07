<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'plan',
        'status',
        'price',
        'payment_provider',
        'external_id',
        'current_period_start',
        'current_period_end',
        'cancelled_at',
    ];

    /**
     * @return array{current_period_start: 'datetime', current_period_end: 'datetime', cancelled_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
