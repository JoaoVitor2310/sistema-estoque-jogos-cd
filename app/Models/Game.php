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
        'steamcharts_id',
        'popularity',
        'price_tf2',
        'price_euro',
        'release_date',
    ];

    public function bundles(): BelongsToMany
    {
        return $this->belongsToMany(Bundle::class, 'bundle_games', 'game_id', 'bundle_id')
            ->using(BundleGame::class)
            ->withPivot('bundle_launch_price')
            ->withTimestamps();
    }

    /** Sempre grava null quando região for string vazia */
    public function setRegionAttribute($value): void
    {
        $this->attributes['region'] = ($value === '' || $value === null) ? null : $value;
    }
}
