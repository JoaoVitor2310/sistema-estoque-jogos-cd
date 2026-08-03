<?php

namespace App\UseCases\Financial;

use App\Domain\Enums\AccountType;
use App\Domain\Enums\FinancialMonthStatus;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Domain\Financial\AccountTransfer;
use App\Models\FinancialMonth;
use App\Services\Financial\FinancialMonthService;
use App\Services\Financial\MovementRecorder;
use Illuminate\Support\Facades\DB;

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
    /** Justificativa das duas pernas geradas pela devolução da verba. */
    private const LEFTOVER_DESCRIPTION = 'Devolução da verba de TF2 não utilizada';

    public function __construct(
        private readonly FinancialMonthService $financialMonthService,
        private readonly MovementRecorder $movementRecorder,
    ) {}

    public function execute(): FinancialMonth
    {
        $month = $this->financialMonthService->currentDraftOrFail();

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
     * Saldo negativo (verba estourada) troca a origem: o Principal cobre.
     */
    private function returnTf2Leftover(FinancialMonth $month): void
    {
        $leftover = $this->financialMonthService->accountBalances($month)[AccountType::Tf2->value];

        if ($leftover === 0.0) {
            return;
        }

        // Quem devolve é a origem da transferência: sobra sai do Tf2, verba
        // estourada sai do Principal (que cobre o rombo). Nos dois casos o Tf2
        // fecha em zero — só muda de que lado o dinheiro vem.
        $transfer = $leftover > 0
            ? AccountTransfer::between(AccountType::Tf2, AccountType::Principal, $leftover, self::LEFTOVER_DESCRIPTION)
            : AccountTransfer::between(AccountType::Principal, AccountType::Tf2, abs($leftover), self::LEFTOVER_DESCRIPTION);

        $this->movementRecorder->record(
            $month,
            $transfer->legs(),
            self::LEFTOVER_DESCRIPTION,
            isGenerated: true,
        );
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

            $next->movements()->create([
                'account_type' => AccountType::from($accountValue),
                'direction' => $balance > 0 ? MovementDirection::Credit : MovementDirection::Debit,
                'category' => MovementCategory::Opening,
                'amount' => abs($balance),
                'description' => 'Saldo de abertura',
                'occurred_at' => now()->toDateString(),
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
}
