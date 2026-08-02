<?php

namespace App\UseCases\Financial;

use App\Domain\Enums\FinancialMonthStatus;
use App\Models\FinancialMonth;
use Illuminate\Support\Facades\DB;

/**
 * Reabre o fechamento mais recente — a correção de um `close` feito por engano.
 *
 * Só o mês fechado mais recente pode ser reaberto (nenhum fechamento depois
 * dele). Reabrir:
 *   - descarta o draft corrente — aquele que o fechamento criou; devolver ESTE
 *     mês ao estado draft não pode deixar dois drafts abertos;
 *   - desfaz os movimentos gerados pelo fechamento (a devolução da verba de TF2);
 *     os lançamentos manuais permanecem intactos;
 *   - volta o mês para `draft`.
 */
class ReopenFinancialMonthUseCase
{
    public function execute(FinancialMonth $month): FinancialMonth
    {
        if ($month->status !== FinancialMonthStatus::Closed) {
            throw new \RuntimeException('Only a closed month can be reopened.');
        }

        $mostRecentClosed = FinancialMonth::where('status', FinancialMonthStatus::Closed)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first();

        if ($mostRecentClosed === null || $mostRecentClosed->id !== $month->id) {
            throw new \RuntimeException('Only the most recent closed month can be reopened.');
        }

        return DB::transaction(function () use ($month) {
            // O draft corrente foi criado por este fechamento; some junto com seus
            // movimentos (cascade). Sem isso, reabrir deixaria dois drafts.
            FinancialMonth::where('status', FinancialMonthStatus::Draft)->delete();

            $month->movements()->where('is_generated', true)->delete();

            $month->update([
                'status' => FinancialMonthStatus::Draft,
                'closed_at' => null,
            ]);

            return $month;
        });
    }
}
