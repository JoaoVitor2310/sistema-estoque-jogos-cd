<?php

namespace App\Http\Requests;

use App\Domain\Enums\TradeCurrency;
use App\Domain\Suppliers\SteamId;
use App\UseCases\Trades\DTO\TradeWithOfferDTO;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Jogos precificados pelo price_researcher para virar trade com oferta.
 *
 * Os jogos seguem a forma do callback `from-price-researcher`. Aqui o supplier
 * é obrigatório — a oferta é feita a alguém —, e precisa ser um SteamID64 de
 * verdade ([[SteamId]]): com ele se cria o supplier novo e a URL do perfil. A
 * moeda é opcional.
 */
class StoreTradeWithOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_steam_id' => ['required', 'string', 'regex:'.SteamId::PATTERN],
            'currency' => ['nullable', Rule::enum(TradeCurrency::class)],
            'title' => ['nullable', 'string', 'max:255'],
            'list_code' => ['nullable', 'string', 'max:255'],
            'games' => ['required', 'array', 'min:1'],
            'games.*.name' => ['required', 'string'],
            'games.*.market_price_euro' => ['required', 'numeric'],
            'games.*.popularity' => ['required', 'integer'],
            'games.*.region' => ['nullable', 'string'],
            'games.*.gamivo_id' => ['nullable', 'string'],
        ];
    }

    public function toDTO(): TradeWithOfferDTO
    {
        return TradeWithOfferDTO::fromValidated($this->validated());
    }
}
