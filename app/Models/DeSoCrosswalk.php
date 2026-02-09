<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeSoCrosswalk extends Model
{
    protected $table = 'deso_crosswalk';

    protected $fillable = [
        'old_code',
        'new_code',
        'overlap_fraction',
        'reverse_fraction',
        'mapping_type',
    ];

    protected function casts(): array
    {
        return [
            'overlap_fraction' => 'float',
            'reverse_fraction' => 'float',
        ];
    }
}
