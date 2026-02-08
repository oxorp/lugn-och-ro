<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    /** @use HasFactory<\Database\Factories\ReportFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'guest_email',
        'lat',
        'lng',
        'address',
        'kommun_name',
        'lan_name',
        'deso_code',
        'score',
        'score_label',
        'stripe_session_id',
        'stripe_payment_intent_id',
        'amount_ore',
        'currency',
        'status',
        'view_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'score' => 'decimal:2',
            'amount_ore' => 'integer',
            'view_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPaid(): bool
    {
        return in_array($this->status, ['completed', 'paid']);
    }
}
