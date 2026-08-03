<?php

namespace App\Domain\Financial;

use App\Domain\Enums\AccountType;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;

/**
 * Movimentação de dinheiro entre duas contas.
 *
 * Sempre em dupla partida: sai da origem, entra no destino, mesmo valor. É isso
 * que mantém o total da empresa intacto — uma transferência não cria nem destrói
 * dinheiro, só o realoca. Por isso `legs()` devolve as duas pernas juntas: quem
 * persiste grava as duas ou nenhuma.
 *
 * O par de contas já declara a intenção (*Principal→Reinvestimento* é o
 * abastecimento da caixinha), então não há categoria dedicada para cada destino
 * — só `transfer`. A exceção é a **alocação de verba de TF2**, que ganha
 * categoria própria por carregar qtd × preço e por ter origem e destino fixos.
 *
 * Quando o valor é uma porcentagem, ela incide sobre o **saldo atual da conta de
 * origem** (`fractionOfBalance`). Seguindo a ordem natural do mês, isso reproduz
 * as bases da antiga cascata sem o sistema rastrear degrau nenhum — ver
 * docs/adr/0005.
 */
final class AccountTransfer
{
    private function __construct(
        public readonly AccountType $source,
        public readonly AccountType $destination,
        public readonly float $amount,
        public readonly MovementCategory $category,
        public readonly ?float $quantity,
        public readonly ?float $unitPrice,
    ) {}

    /**
     * Transfere um valor fechado entre duas contas.
     *
     * Recusa origem igual ao destino — seria uma linha de débito e uma de
     * crédito na mesma conta, que se anulam e só poluem o extrato — e recusa
     * valor não positivo: o sentido do dinheiro é o par de contas, não o sinal,
     * então um valor negativo seria uma segunda forma de dizer a mesma coisa.
     *
     * @param  float  $amount  valor a transferir (R$), sempre positivo
     * @param  string|null  $description  justificativa; obrigatória se a origem for caixinha
     */
    public static function between(
        AccountType $source,
        AccountType $destination,
        float $amount,
        ?string $description = null,
    ): self {
        if ($source === $destination) {
            throw new \InvalidArgumentException('A transfer needs two different accounts.');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('The transferred amount must be positive.');
        }

        JustificationPolicy::guardDebit($source, $description);

        return new self($source, $destination, $amount, MovementCategory::Transfer, null, null);
    }

    /**
     * Transfere uma fração do saldo atual da origem, arredondada half-up ao centavo.
     *
     * @param  float  $sourceBalance  saldo da conta de origem no momento do lançamento
     * @param  float  $fraction  fração a transferir (ex: 0.20 para 20%)
     */
    public static function fractionOfBalance(
        AccountType $source,
        AccountType $destination,
        float $sourceBalance,
        float $fraction,
        ?string $description = null,
    ): self {
        if ($fraction < 0 || $fraction > 1) {
            throw new \InvalidArgumentException("The fraction must be between 0 and 1, got {$fraction}.");
        }

        if ($sourceBalance <= 0) {
            throw new \InvalidArgumentException('A fraction can only be taken from a positive balance.');
        }

        $amount = Money::fromReais($sourceBalance)->percentage($fraction)->toReais();

        return self::between($source, $destination, $amount, $description);
    }

    /**
     * Separa a verba de TF2 do mês: sempre Principal → Tf2, com a meta
     * (qtd × preço unitário) registrada para comparar com as compras reais.
     */
    public static function tf2Allocation(float $quantity, float $unitPrice): self
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Field quantity must be a positive value.');
        }

        if ($unitPrice <= 0) {
            throw new \InvalidArgumentException('Field unitPrice must be a positive value.');
        }

        return new self(
            AccountType::Principal,
            AccountType::Tf2,
            Money::fromReais($quantity * $unitPrice)->toReais(),
            MovementCategory::Tf2Allocation,
            $quantity,
            $unitPrice,
        );
    }

    /**
     * As duas linhas do extrato: a saída da origem e a entrada no destino.
     *
     * @return array{MovementLeg, MovementLeg}
     */
    public function legs(): array
    {
        return [
            new MovementLeg(
                $this->source,
                MovementDirection::Debit,
                $this->category,
                $this->amount,
                $this->quantity,
                $this->unitPrice,
            ),
            new MovementLeg(
                $this->destination,
                MovementDirection::Credit,
                $this->category,
                $this->amount,
                $this->quantity,
                $this->unitPrice,
            ),
        ];
    }
}
