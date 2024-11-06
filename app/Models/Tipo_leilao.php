<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tipo_leilao extends Model
{
    use HasFactory;

    protected $table = 'tipo_leilao';

    protected $fillable= [
        'id',
        'name',
      ];


}
