<?php

namespace App\UseCases\Financial;

use App\Domain\Financial\ManualMovement;
use App\Models\FinancialMovement;
use App\Services\Financial\FinancialMonthService;
use App\UseCases\Financial\DTO\RecordMovementDTO;

/**
 * Lança um movimento simples no fechamento em aberto.
 *
 * Constrói o VO `ManualMovement` a partir da entrada — é ele quem resolve conta
 * e direção pela categoria e recusa o que viola o domínio (valor não positivo,
 * caixinha debitada sem justificativa). A construção acontece aqui, e não na
 * fronteira HTTP, para que o erro de negócio nasça na camada que o reporta.
 *
 * Persiste como manual (`is_generated = false`), anexando os campos que são só
 * de registro (justificativa e data).
 *
 * Gera uma linha só, então não passa pelo `MovementRecorder` nem ganha
 * `group_id`: não há par para manter junto.
 */
class RecordMovementUseCase
{
    public function __construct(
        private readonly FinancialMonthService $financialMonthService,
    ) {}

    public function execute(RecordMovementDTO $data): FinancialMovement
    {
        $month = $this->financialMonthService->currentDraftOrFail();

        $movement = ManualMovement::make(
            $data->category,
            account: $data->account,
            amount: $data->amount,
            quantity: $data->quantity,
            unitPrice: $data->unitPrice,
            description: $data->description,
        );

        return $month->movements()->create([
            'account_type' => $movement->account,
            'direction' => $movement->direction,
            'category' => $movement->category,
            'amount' => $movement->amount,
            'quantity' => $movement->quantity,
            'unit_price' => $movement->unitPrice,
            'description' => $data->description,
            'occurred_at' => $data->occurredAt ?? now()->toDateString(),
            'is_generated' => false,
        ]);
    }
}
