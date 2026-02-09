<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DesoArea2018 extends Model
{
    protected $table = 'deso_areas_2018';

    protected $fillable = [
        'deso_code',
        'deso_name',
        'kommun_code',
        'kommun_name',
    ];
}
