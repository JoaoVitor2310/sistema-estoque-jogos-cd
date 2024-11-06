<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tipo_formato extends Model
{
    use HasFactory;

    protected $table = 'tipo_formato';

    protected $fillable = [
        'id',
        'name',
    ];
}
