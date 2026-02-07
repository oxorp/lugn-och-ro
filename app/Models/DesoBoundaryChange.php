<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DesoBoundaryChange extends Model
{
    protected $fillable = [
        'deso_2018_code',
        'deso_2025_code',
        'change_type',
        'notes',
    ];
}
