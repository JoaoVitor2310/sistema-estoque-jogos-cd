<?php

namespace App\UseCases\Financial;

use App\Domain\Financial\AccountTransfer;
use App\Models\FinancialMovement;
use App\Services\Financial\FinancialMonthService;
use App\Services\Financial\MovementRecorder;
use App\UseCases\Financial\DTO\RecordTransferDTO;
use Illuminate\Support\Collection;

/**
 * Move dinheiro entre duas contas do fechamento em aberto.
 *
 * Cobre tanto o abastecimento das caixinhas (passos 4 e 5 do roteiro) quanto
 * qualquer devolução ao Principal: o par de contas já declara a intenção, então
 * uma categoria `transfer` basta.
 *
 * Grava as **duas** pernas num só grupo — quem monta o par é o `AccountTransfer`,
 * quem garante a atomicidade é o `MovementRecorder`.
 */
class RecordTransferUseCase
{
    public function __construct(
        private readonly FinancialMonthService $financialMonthService,
        private readonly MovementRecorder $movementRecorder,
    ) {}

    /**
     * @return Collection<int, FinancialMovement>
     */
    public function execute(RecordTransferDTO $data): Collection
    {
        $month = $this->financialMonthService->currentDraftOrFail();

        if ($data->amount === null && $data->fraction === null) {
            throw new \InvalidArgumentException('A transfer needs an amount or a fraction of the source balance.');
        }

        if ($data->amount !== null && $data->fraction !== null) {
            throw new \InvalidArgumentException('A transfer takes an amount or a fraction, not both.');
        }

        // A porcentagem incide sobre o saldo do momento do lançamento: seguindo a
        // ordem do roteiro, o Principal já está pós-TF2 e pós-gastos quando as
        // caixinhas são abastecidas, o que reproduz as bases da antiga cascata.
        $transfer = $data->fraction !== null
            ? AccountTransfer::fractionOfBalance(
                $data->source,
                $data->destination,
                $this->financialMonthService->accountBalances($month)[$data->source->value],
                $data->fraction,
                $data->description,
            )
            : AccountTransfer::between(
                $data->source,
                $data->destination,
                $data->amount,
                $data->description,
            );

        return $this->movementRecorder->record(
            $month,
            $transfer->legs(),
            $data->description,
            $data->occurredAt,
        );
    }
}
