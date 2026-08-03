<?php

namespace App\UseCases\Financial\DTO;

use App\Domain\Enums\AccountType;

/**
 * Entrada de uma transferência entre contas, já validada na fronteira HTTP.
 *
 * `amount` e `fraction` são alternativos: ou se transfere um valor fechado, ou
 * uma porcentagem do saldo atual da origem (ex: 0.20 para os 20% do
 * Reinvestimento). Exatamente um dos dois — o UseCase recusa nenhum e recusa
 * ambos, para não escolher silenciosamente por quem lançou.
 *
 * A porcentagem vem sempre de quem lança; a tela a pré-preenche com a do mês,
 * mas o sistema nunca a aplica sozinho (docs/adr/0005).
 */
final class RecordTransferDTO
{
    public function __construct(
        public readonly AccountType $source,
        public readonly AccountType $destination,
        public readonly ?float $amount = null,
        public readonly ?float $fraction = null,
        public readonly ?string $description = null,
        public readonly ?string $occurredAt = null,
    ) {}
}
