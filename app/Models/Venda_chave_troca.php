<?php

namespace App\Models;

use App\Domain\Enums\ClaimType;
use App\Domain\Enums\KeyFormat;
use App\Domain\Enums\SellPlatform;
use Carbon\Carbon;
use Database\Factories\VendaChaveTrocaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Venda_chave_troca extends Model
{
    use HasFactory;

    protected $table = 'venda_chave_trocas';

    protected $fillable = [
        'id',
        'color',
        'id_fornecedor',
        'claim_type',
        'steamId',
        'gamivo_id',
        'key_code',
        'is_duplicate',
        'identified_platform',
        'game_name',
        'region',
        'precoJogo',
        'observacao',
        'key_format',
        'sell_platform',
        'market_price',
        'minimum_sale_price',
        'simulated_income',
        'total_paid',
        'tf2_quantity',
        'individual_cost',
        'purchase_profit',
        'purchase_profit_percent',
        'sold_price',
        'sale_profit',
        'sale_profit_percent',
        'acquired_at',
        'listed_at',
        'sold_at',
        'expires_at',
        'supplier_url',
        'minApiGamivo',
        'maxApiGamivo',
        'email',
    ];

    protected $casts = [
        'key_format'    => KeyFormat::class,
        'claim_type' => ClaimType::class,
        'sell_platform' => SellPlatform::class,
    ];

    public function fornecedor()
    {
        return $this->belongsTo(Fornecedor::class, 'id_fornecedor');
    }

    public function game()
    {
        return $this->belongsTo(Game::class, 'gamivo_id', 'id_gamivo');
    }

    public function scopeRegisteredOnGamivo(Builder $query): void
    {
        $query->whereNotNull('gamivo_id')->where('gamivo_id', '!=', '');
    }

    public function scopeNotYetListed(Builder $query): void
    {
        $query->whereNull('listed_at')->whereNull('sold_at');
    }

    public function scopeNotGiftLink(Builder $query): void
    {
        $query->where('key_code', 'not like', '%http%');
    }

    public function scopeWithoutRecentBundle(Builder $query, int $days): void
    {
        $query->whereDoesntHave(
            'game.bundles',
            fn (Builder $b) => $b->where('bundles.release_date', '>', Carbon::now()->subDays($days))
        );
    }

    /** Sempre grava null quando região for string vazia */
    public function setRegionAttribute($value): void
    {
        $this->attributes['region'] = ($value === '' || $value === null) ? null : $value;
    }

    protected static function newFactory()
    {
        return VendaChaveTrocaFactory::new();
    }
}
