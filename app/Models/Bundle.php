<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Bundle extends Model
{
    use HasFactory;

    protected $table = 'bundles';

    protected $fillable = [
        'id',
        'name',
        'type',
        'description',
        'minimum_price_tf2',
        'price_dolar',
        'release_date',
    ];

    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class, 'bundle_games', 'bundle_id', 'game_id');
    }
}
