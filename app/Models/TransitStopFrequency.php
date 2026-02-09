<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransitStopFrequency extends Model
{
    protected $fillable = [
        'gtfs_stop_id',
        'mode_category',
        'departures_06_09',
        'departures_09_15',
        'departures_15_18',
        'departures_18_22',
        'departures_06_20_total',
        'distinct_routes',
        'day_type',
        'feed_version',
    ];

    public function stop(): BelongsTo
    {
        return $this->belongsTo(TransitStop::class, 'gtfs_stop_id', 'gtfs_stop_id');
    }
}
