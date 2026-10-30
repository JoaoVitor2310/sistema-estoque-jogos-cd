<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O valor de um jogo numa oferta, na moeda dela. Imutável como a oferta a que
 * pertence.
 *
 * `game_name` e `region` são cópia do que o supplier leu, não referência: a
 * linha da trade muda depois, e a oferta continua dizendo o que foi ofertado.
 */
class TradeOfferLine extends Model
{
    public $timestamps = false;

    protected $table = 'trade_offer_lines';

    protected $fillable = ['trade_offer_id', 'trade_line_id', 'game_name', 'region', 'price'];

    protected $casts = [
        'price' => 'float',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('A trade offer line is a record and cannot be changed; record a new offer instead.');
        });
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(TradeOffer::class, 'trade_offer_id');
    }

    public function tradeLine(): BelongsTo
    {
        return $this->belongsTo(TradeLine::class);
    }
}
