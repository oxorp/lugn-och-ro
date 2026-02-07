<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserUnlock extends Model
{
    protected $fillable = [
        'user_id',
        'unlock_type',
        'unlock_code',
        'payment_reference',
        'price_paid',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
