<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trade extends Model
{
    protected $table = 'trades';

    protected $fillable = ['title', 'rows'];

    protected $casts = [
        'rows' => 'array',
    ];
}
