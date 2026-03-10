<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vip extends Model
{
    protected $table = 'vips';

    protected $fillable = [
        'name',
        'first_link',
        'second_link',
        'third_link',
        'steam_link',
    ];
}
