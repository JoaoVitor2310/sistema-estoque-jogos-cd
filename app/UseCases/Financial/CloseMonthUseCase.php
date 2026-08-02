<?php

namespace App\UseCases\Financial;

use App\Domain\Enums\AccountType;
use App\Domain\Enums\FinancialMonthStatus;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Models\FinancialMonth;
use App\Services\Financial\FinancialMonthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Encerra o mês corrente e abre o próximo.
 *
 * **Não distribui nada.** Transferências para as caixinhas e saques dos sócios
 * são lançamentos explícitos que o usuário faz durante o mês (ver docs/adr/0005);
 * o fechamento apenas devolve o que sobrou da verba de TF2 e vira a página.
 *
 * O único movimento gerado aqui é a **devolução da sobra**: um `transfer`
 * Tf2 → Principal, no mês que encerra. Com isso a conta Tf2 fecha em zero e o
 * carry-forward fica uniforme — toda conta abre o mês seguinte com o próprio
 * saldo. Se a verba foi estourada (saldo Tf2 negativo), a devolução inverte de
 * direção e vira débito no Principal, que é o efeito correto.
 *
 * Carry-forward: o próximo draft herda as porcentagens (só como prefill de
 * formulário) e os saldos, como movimentos `opening`. Esses `opening` pertencem
 * ao próximo mês, não a este fechamento, por isso nascem manuais
 * (`is_generated = false`) e sobrevivem a uma reabertura futura daquele mês.
 */
class CloseMonthUseCase
{
    public function __construct(
        private readonly FinancialMonthService $financialMonthService,
    ) {}

    public function execute(): FinancialMonth
    {
        $month = FinancialMonth::where('status', FinancialMonthStatus::Draft)->first();

        if ($month === null) {
            throw new \RuntimeException('There is no open financial month.');
        }

        return DB::transaction(function () use ($month) {
            $this->returnTf2Leftover($month);

            $month->update([
                'status' => FinancialMonthStatus::Closed,
                'closed_at' => now(),
            ]);

            $this->openNextDraft($month);

            return $month;
        });
    }

    /**
     * Devolve ao Principal o que sobrou da verba de TF2, zerando a conta.
     * Saldo negativo (verba estourada) inverte a direção: o Principal cobre.
     */
    private function returnTf2Leftover(FinancialMonth $month): void
    {
        $leftover = $this->financialMonthService->accountBalances($month)[AccountType::Tf2->value];

        if ($leftover === 0.0) {
            return;
        }

        $groupId = (string) Str::uuid();
        $returning = $leftover > 0;

        $this->writeMovement($month, [
            'group_id' => $groupId,
            'account_type' => AccountType::Tf2,
            'direction' => $returning ? MovementDirection::Debit : MovementDirection::Credit,
            'category' => MovementCategory::Transfer,
            'amount' => abs($leftover),
            'description' => 'Devolução da verba de TF2 não utilizada',
            'is_generated' => true,
        ]);

        $this->writeMovement($month, [
            'group_id' => $groupId,
            'account_type' => AccountType::Principal,
            'direction' => $returning ? MovementDirection::Credit : MovementDirection::Debit,
            'category' => MovementCategory::Transfer,
            'amount' => abs($leftover),
            'description' => 'Devolução da verba de TF2 não utilizada',
            'is_generated' => true,
        ]);
    }

    /**
     * Abre o próximo draft herdando as porcentagens; os saldos de fecho viram
     * movimentos `opening`.
     */
    private function openNextDraft(FinancialMonth $month): void
    {
        [$year, $monthNumber] = $this->nextYearMonth($month);

        $next = FinancialMonth::create([
            'year' => $year,
            'month' => $monthNumber,
            'status' => FinancialMonthStatus::Draft,
            'reinvestment_percent' => $month->reinvestment_percent,
            'emergency_percent' => $month->emergency_percent,
            'partner_one_share' => $month->partner_one_share,
        ]);

        foreach ($this->financialMonthService->accountBalances($month->fresh()) as $accountValue => $balance) {
            if ($balance === 0.0) {
                continue;
            }

            $this->writeMovement($next, [
                'account_type' => AccountType::from($accountValue),
                'direction' => $balance > 0 ? MovementDirection::Credit : MovementDirection::Debit,
                'category' => MovementCategory::Opening,
                'amount' => abs($balance),
                'description' => 'Saldo de abertura',
                'is_generated' => false,
            ]);
        }
    }

    /**
     * @return array{int, int} ano e mês seguintes, virando o ano em dezembro
     */
    private function nextYearMonth(FinancialMonth $month): array
    {
        return $month->month === 12
            ? [$month->year + 1, 1]
            : [$month->year, $month->month + 1];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function writeMovement(FinancialMonth $month, array $attributes): void
    {
        $month->movements()->create($attributes + ['occurred_at' => now()->toDateString()]);
    }
}
