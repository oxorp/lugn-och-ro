<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DesoH3Mapping extends Model
{
    protected $table = 'deso_h3_mapping';

    protected $fillable = [
        'deso_code',
        'h3_index',
        'area_weight',
        'resolution',
    ];

    protected function casts(): array
    {
        return [
            'area_weight' => 'decimal:6',
        ];
    }
}
