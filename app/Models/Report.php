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
        // Snapshot columns
        'area_indicators',
        'proximity_factors',
        'schools',
        'category_verdicts',
        'score_history',
        'deso_meta',
        'national_references',
        'map_snapshot',
        'outlook',
        'top_positive',
        'top_negative',
        'priorities',
        'default_score',
        'personalized_score',
        'trend_1y',
        'model_version',
        'indicator_count',
        'year',
        'isochrone',
        'isochrone_mode',
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
            'default_score' => 'decimal:2',
            'personalized_score' => 'decimal:2',
            'trend_1y' => 'decimal:2',
            'amount_ore' => 'integer',
            'view_count' => 'integer',
            'indicator_count' => 'integer',
            'year' => 'integer',
            'area_indicators' => 'array',
            'proximity_factors' => 'array',
            'schools' => 'array',
            'category_verdicts' => 'array',
            'score_history' => 'array',
            'deso_meta' => 'array',
            'national_references' => 'array',
            'map_snapshot' => 'array',
            'outlook' => 'array',
            'top_positive' => 'array',
            'top_negative' => 'array',
            'priorities' => 'array',
            'isochrone' => 'array',
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
