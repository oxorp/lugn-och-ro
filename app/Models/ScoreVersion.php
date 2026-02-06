<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScoreVersion extends Model
{
    protected $fillable = [
        'year',
        'status',
        'indicators_used',
        'ingestion_log_ids',
        'validation_summary',
        'sentinel_results',
        'deso_count',
        'mean_score',
        'stddev_score',
        'computed_by',
        'notes',
        'computed_at',
        'published_at',
    ];

    /**
     * @return array{indicators_used: 'array', ingestion_log_ids: 'array', validation_summary: 'array', sentinel_results: 'array', mean_score: 'decimal:2', stddev_score: 'decimal:2', computed_at: 'datetime', published_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'indicators_used' => 'array',
            'ingestion_log_ids' => 'array',
            'validation_summary' => 'array',
            'sentinel_results' => 'array',
            'mean_score' => 'decimal:2',
            'stddev_score' => 'decimal:2',
            'computed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function compositeScores(): HasMany
    {
        return $this->hasMany(CompositeScore::class);
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }
}
