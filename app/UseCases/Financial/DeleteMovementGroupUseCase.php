<?php

namespace App\UseCases\Financial;

use App\Domain\Financial\MovementDeletionPolicy;
use App\Models\FinancialMovement;

/**
 * Apaga um lançamento inteiro a partir de qualquer uma de suas linhas.
 *
 * A tela lista linhas do extrato, mas a unidade de correção é o **lançamento**:
 * uma transferência gravou o débito na origem *e* o crédito no destino, e uma
 * distribuição gravou um débito por sócio. Apagar só a linha clicada criaria ou
 * destruiria dinheiro — tirar o débito de uma transferência de R$ 500 devolveria
 * os 500 à origem sem tirá-los do destino, inflando o total da empresa.
 *
 * Por isso o alvo é o `group_id` da linha, não o id dela. Lançamento simples
 * (uma entrada, uma saída) nasce sem grupo — não há par para manter junto —,
 * então some sozinho.
 *
 * Retorna quantas linhas sumiram.
 */
class DeleteMovementGroupUseCase
{
    public function execute(FinancialMovement $movement): int
    {
        MovementDeletionPolicy::guard(
            $movement->financialMonth->status,
            $movement->is_generated,
            $movement->category,
        );

        if ($movement->group_id === null) {
            $movement->delete();

            return 1;
        }

        // Um DELETE só, então já é atômico — o `group_id` é uuid, não colide
        // entre lançamentos nem entre meses.
        return FinancialMovement::where('group_id', $movement->group_id)->delete();
    }
}
