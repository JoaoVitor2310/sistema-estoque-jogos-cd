<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ranges_taxa_G2A extends Model
{
    use HasFactory;

    protected $table = 'ranges_taxa_g2a';

    protected $fillable= [
        'id',
        'minimo',
        'maximo',
        'taxa',
      ];
}
