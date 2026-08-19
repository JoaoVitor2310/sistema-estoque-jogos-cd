<?php

namespace App\UseCases\Trades\DTO;

use App\Domain\Trades\TradeLineValue;

/**
 * O que o supplier escreve na própria trade: o total de TF2 acertado e a
 * observação livre.
 *
 * **Ausente e nulo não são a mesma coisa**, pela mesma razão de
 * [[TradeLineDTO]]: a página salva conforme ele digita e manda um campo por vez,
 * então mandar `tf2_qty` sozinho não pode apagar o recado que ele já tinha
 * escrito. Daí o `$provided`.
 *
 * O escopo do supplier sobre a trade é **a própria forma desta classe** — ela
 * não sabe carregar outra coluna. Não há aqui, como há na escrita de linha, um
 * caminho compartilhado com a equipe a restringir: `UpdateTradeUseCase` é outro
 * caminho, com outros campos. Ver docs/adr/0008.
 */
final class DeliveryTradeDTO
{
    /** As únicas colunas da trade que a entrega alcança. */
    private const COLUMNS = ['tf2_qty', 'supplier_notes'];

    /**
     * @param  list<string>  $provided  colunas presentes no payload
     */
    private function __construct(
        public readonly ?string $tf2Quantity,
        public readonly ?string $supplierNotes,
        public readonly array $provided,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  saída de `DeliveryTradeRequest::validated()`
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            tf2Quantity: TradeLineValue::decimal($validated['tf2_qty'] ?? null),
            supplierNotes: TradeLineValue::text($validated['supplier_notes'] ?? null),
            provided: array_values(array_filter(
                self::COLUMNS,
                fn (string $column) => array_key_exists($column, $validated),
            )),
        );
    }

    /**
     * Só as colunas que o payload trouxe, prontas para gravar.
     *
     * @return array<string, string|null>
     */
    public function toAttributes(): array
    {
        $all = [
            'tf2_qty' => $this->tf2Quantity,
            'supplier_notes' => $this->supplierNotes,
        ];

        return array_intersect_key($all, array_flip($this->provided));
    }
}
