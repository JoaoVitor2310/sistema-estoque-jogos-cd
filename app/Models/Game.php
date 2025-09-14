<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Game extends Model
{
    use HasFactory;

    protected $table = 'games';

    protected $fillable = [
        'id',
        'name',
        'region',
        'id_gamivo',
        'id_steamcharts',
        'popularity',
        'price_tf2',
        'price_euro',
        'release_date',
    ];

    public function bundles(): BelongsToMany
    {
        return $this->belongsToMany(Bundle::class, 'bundle_games', 'game_id', 'bundle_id');
    }
}
