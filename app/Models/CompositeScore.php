<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompositeScore extends Model
{
    protected $fillable = [
        'deso_code',
        'year',
        'score_version_id',
        'score',
        'trend_1y',
        'trend_3y',
        'factor_scores',
        'top_positive',
        'top_negative',
        'computed_at',
    ];

    /**
     * @return array{score: 'decimal:2', trend_1y: 'decimal:2', trend_3y: 'decimal:2', factor_scores: 'array', top_positive: 'array', top_negative: 'array', computed_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'trend_1y' => 'decimal:2',
            'trend_3y' => 'decimal:2',
            'factor_scores' => 'array',
            'top_positive' => 'array',
            'top_negative' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function scoreVersion(): BelongsTo
    {
        return $this->belongsTo(ScoreVersion::class);
    }
}
