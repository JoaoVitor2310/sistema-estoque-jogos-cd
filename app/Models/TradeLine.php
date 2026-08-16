<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeLine extends Model
{
    protected $table = 'trade_lines';

    protected $fillable = [
        'trade_id', 'position', 'game_name', 'market_price',
        'popularity', 'region', 'bundle', 'expires_at', 'key_code', 'gamivo_id',
    ];

    protected $casts = [
        'position' => 'integer',
        'market_price' => 'decimal:2',
        'popularity' => 'integer',
        'expires_at' => 'date',
    ];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
