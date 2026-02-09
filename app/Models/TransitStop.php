<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransitStop extends Model
{
    protected $fillable = [
        'gtfs_stop_id',
        'name',
        'lat',
        'lng',
        'parent_station',
        'location_type',
        'source',
        'stop_type',
        'weekly_departures',
        'routes_count',
        'deso_code',
    ];

    public function frequencies(): HasMany
    {
        return $this->hasMany(TransitStopFrequency::class, 'gtfs_stop_id', 'gtfs_stop_id');
    }
}
