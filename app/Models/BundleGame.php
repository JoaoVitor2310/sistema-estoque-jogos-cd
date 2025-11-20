<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class BundleGame extends Pivot
{
    protected $table = 'bundle_games';

    protected $fillable = [
        'bundle_id',
        'game_id',
        'bundle_launch_price',
    ];

    protected $casts = [
        'bundle_launch_price' => 'decimal:2',
    ];

    public function bundle()
    {
        return $this->belongsTo(Bundle::class, 'bundle_id', 'id');
    }

    public function game()
    {
        return $this->belongsTo(Game::class, 'game_id', 'id');
    }
}
