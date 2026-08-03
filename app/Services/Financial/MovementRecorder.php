<?php

namespace App\Services\Financial;

use App\Domain\Financial\MovementLeg;
use App\Models\FinancialMonth;
use App\Models\FinancialMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lado de escrita do livro-caixa: grava no banco as pernas de um lançamento.
 *
 * Um lançamento vira uma ou mais linhas — uma transferência grava a saída da
 * origem *e* a entrada no destino; uma distribuição grava um débito por sócio.
 * Todas nascem com o mesmo `group_id` e dentro de uma transação: ou o conjunto
 * inteiro entra, ou nada entra. Gravar meia transferência criaria ou destruiria
 * dinheiro, e é o `group_id` que permite apagá-las juntas depois (docs/adr/0005).
 *
 * Justificativa e data são campos de registro, não de domínio — valem para o
 * lançamento inteiro, então se repetem em cada perna.
 *
 * `$isGenerated` marca o que o sistema lançou sozinho (hoje só a devolução da
 * verba de TF2, no fechamento). O que é gerado não se apaga à mão: quem desfaz
 * é o `reopen`, em bloco — ver `MovementDeletionPolicy`.
 */
class MovementRecorder
{
    /**
     * @param  list<MovementLeg>  $legs
     * @return Collection<int, FinancialMovement>
     */
    public function record(
        FinancialMonth $month,
        array $legs,
        ?string $description = null,
        ?string $occurredAt = null,
        bool $isGenerated = false,
    ): Collection {
        $groupId = (string) Str::uuid();
        $occurredAt ??= now()->toDateString();

        return DB::transaction(fn (): Collection => collect($legs)->map(
            fn (MovementLeg $leg): FinancialMovement => $month->movements()->create([
                'group_id' => $groupId,
                'account_type' => $leg->account,
                'direction' => $leg->direction,
                'category' => $leg->category,
                'amount' => $leg->amount,
                'quantity' => $leg->quantity,
                'unit_price' => $leg->unitPrice,
                'partner_slot' => $leg->partnerSlot,
                'description' => $description,
                'occurred_at' => $occurredAt,
                'is_generated' => $isGenerated,
            ])
        ));
    }
}
