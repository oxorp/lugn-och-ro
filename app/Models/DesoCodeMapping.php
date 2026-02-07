<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DesoCodeMapping extends Model
{
    protected $fillable = [
        'old_code',
        'new_code',
        'mapping_type',
    ];
}
