<?php

namespace App\UseCases\Financial;

use App\Domain\Financial\AccountTransfer;
use App\Models\FinancialMovement;
use App\Services\Financial\FinancialMonthService;
use App\Services\Financial\MovementRecorder;
use App\UseCases\Financial\DTO\RecordTf2AllocationDTO;
use Illuminate\Support\Collection;

/**
 * Separa a verba de TF2 do mês: débito no Principal, crédito no Tf2.
 *
 * É uma transferência com origem e destino fixos, mas categoria própria
 * (`tf2_allocation`) por carregar a meta — qtd × preço unitário — que depois se
 * compara com as compras reais (`tf2_purchase`). O dinheiro sai do Principal de
 * verdade: a verba é conta, não reserva virtual (docs/adr/0005).
 */
class RecordTf2AllocationUseCase
{
    public function __construct(
        private readonly FinancialMonthService $financialMonthService,
        private readonly MovementRecorder $movementRecorder,
    ) {}

    /**
     * @return Collection<int, FinancialMovement>
     */
    public function execute(RecordTf2AllocationDTO $data): Collection
    {
        $month = $this->financialMonthService->currentDraftOrFail();

        $allocation = AccountTransfer::tf2Allocation($data->quantity, $data->unitPrice);

        return $this->movementRecorder->record(
            $month,
            $allocation->legs(),
            $data->description,
            $data->occurredAt,
        );
    }
}
