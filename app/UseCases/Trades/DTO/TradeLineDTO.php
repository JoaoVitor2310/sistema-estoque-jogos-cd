<?php

namespace App\UseCases\Trades\DTO;

use App\Domain\Enums\TradeLineAuthority;
use App\Domain\Trades\TradeLineValue;

/**
 * Entrada de escrita de uma linha de trade, já validada e normalizada na
 * fronteira HTTP.
 *
 * **Ausente e nulo não são a mesma coisa aqui.** Mandar só `key_code` não pode
 * zerar o nome do jogo; mandar `region` vazia deve limpar a região. Por isso o
 * DTO carrega, além dos valores, quais colunas o payload realmente trouxe —
 * `$provided`. É o que torna a escrita um patch parcial, e é o que a entrega
 * pelo supplier vai usar para alcançar só os três campos dela (ver
 * [`docs/adr/0008`](../../../../docs/adr/0008-supplier-fills-trade-through-tokenised-link.md)).
 *
 * `position` fica de fora dos atributos de propósito: onde a linha entra é
 * decisão do UseCase de criação, não um campo que se grava junto.
 */
final class TradeLineDTO
{
    /** Colunas que o DTO sabe carregar, na ordem em que a linha é lida. */
    private const COLUMNS = [
        'game_name', 'market_price', 'popularity', 'region',
        'bundle', 'expires_at', 'key_code', 'gamivo_id',
    ];

    /**
     * @param  list<string>  $provided  colunas presentes no payload
     */
    private function __construct(
        public readonly ?string $gameName,
        public readonly ?string $marketPrice,
        public readonly ?int $popularity,
        public readonly ?string $region,
        public readonly ?string $bundle,
        public readonly ?string $expiresAt,
        public readonly ?string $keyCode,
        public readonly ?string $gamivoId,
        public readonly ?int $position,
        public readonly array $provided,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  saída de `TradeLineRequest::validated()`
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            gameName: TradeLineValue::text($validated['game_name'] ?? null),
            marketPrice: TradeLineValue::decimal($validated['market_price'] ?? null),
            popularity: TradeLineValue::integer($validated['popularity'] ?? null),
            region: TradeLineValue::text($validated['region'] ?? null),
            bundle: TradeLineValue::text($validated['bundle'] ?? null),
            // Formato da equipe: quem escreve noutro formato converte antes de chegar
            // aqui (ver [[App\Http\Requests\DeliveryLineRequest]]).
            expiresAt: TradeLineValue::date($validated['expires_at'] ?? null, TradeLineAuthority::Team->dateFormat()),
            keyCode: TradeLineValue::text($validated['key_code'] ?? null),
            gamivoId: TradeLineValue::text($validated['gamivo_id'] ?? null),
            position: isset($validated['position']) ? (int) $validated['position'] : null,
            provided: array_values(array_filter(
                self::COLUMNS,
                fn (string $column) => array_key_exists($column, $validated),
            )),
        );
    }

    /**
     * Só as colunas que o payload trouxe, prontas para gravar.
     *
     * @return array<string, string|int|null>
     */
    public function toAttributes(): array
    {
        $all = [
            'game_name' => $this->gameName,
            'market_price' => $this->marketPrice,
            'popularity' => $this->popularity,
            'region' => $this->region,
            'bundle' => $this->bundle,
            'expires_at' => $this->expiresAt,
            'key_code' => $this->keyCode,
            'gamivo_id' => $this->gamivoId,
        ];

        return array_intersect_key($all, array_flip($this->provided));
    }
}
