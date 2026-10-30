<?php

namespace App\Models;

use App\Domain\Enums\TradeCurrency;
use App\Domain\Pricing\OfferCalculator;
use App\Domain\Pricing\SupplierOffer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma oferta feita a um supplier numa trade, numa rodada.
 *
 * **Imutável:** uma oferta enviada é um fato, e a rodada seguinte cria outra
 * em vez de reescrever esta (docs/adr/0016). A gravação de uma alteração é
 * recusada no próprio model, para que nenhum caminho a reescreva por engano.
 */
class TradeOffer extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'trade_offers';

    protected $fillable = ['trade_id', 'round', 'profit_percent', 'currency', 'tf2_rate', 'requested_total'];

    protected $casts = [
        'round' => 'integer',
        'profit_percent' => 'float',
        'currency' => TradeCurrency::class,
        'tf2_rate' => 'float',
        'requested_total' => 'float',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('A trade offer is a record and cannot be changed; record a new one instead.');
        });
    }

    /** É a última rodada: não há contraproposta depois dela. */
    public function isFinal(): bool
    {
        return SupplierOffer::isFinalRound($this->round);
    }

    /**
     * O total da oferta: a soma dos valores já arredondados de cada jogo, que é
     * o que o supplier lê linha a linha.
     */
    public function totalPrice(): float
    {
        return round((float) $this->lines->sum('price'), OfferCalculator::MONEY_DECIMALS);
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TradeOfferLine::class)->orderBy('id');
    }
}
