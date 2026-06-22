<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $table = 'suppliers';

    protected $fillable = [
        'id',
        'steam_id',
        'url',
        'is_added',
    ];

    protected $casts = [
        'is_added' => 'boolean',
    ];

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }
}
