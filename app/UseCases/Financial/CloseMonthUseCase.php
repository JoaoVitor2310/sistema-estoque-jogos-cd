<?php

namespace App\UseCases\Financial;

use App\Domain\Enums\AccountType;
use App\Domain\Enums\FinancialMonthStatus;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Domain\Financial\FinancialMonthCalculator;
use App\Domain\Financial\FinancialMonthResult;
use App\Domain\Financial\Money;
use App\Models\FinancialMonth;
use Illuminate\Support\Facades\DB;

/**
 * Fecha o mês corrente: roda a cascata, congela o snapshot, gera os movimentos
 * de transferência/distribuição e abre o próximo draft herdando o estado.
 *
 * O que a cascata consome do mês:
 *   - Σ entradas   = movimentos `income`
 *   - Σ saídas op.  = movimentos `expense`
 * `tf2_purchase` (compra real) e `opening` (saldo herdado) NÃO entram na cascata:
 * a reserva TF2 é earmark virtual e a compra real é comparada à meta à parte.
 *
 * Movimentos gerados (is_generated = true; a reabertura desfaz só estes):
 *   - transferências: débito no Principal + crédito na caixa (dupla partida)
 *   - distribuição: um débito no Principal por sócio
 * A reserva TF2 NÃO vira movimento — o dinheiro permanece no Principal.
 *
 * Carry-forward: o próximo draft nasce com os saldos de fecho como movimentos
 * `opening` e a meta de TF2 acrescida do incremento; taxas, split, nomes e preço
 * são herdados como snapshot. Os `opening` do carry-forward são manuais
 * (is_generated = false): pertencem ao próximo mês, não ao fechamento deste, e
 * não devem ser desfeitos se o próximo mês for reaberto no futuro.
 */
class CloseMonthUseCase
{
    public function execute(): FinancialMonth
    {
        $month = FinancialMonth::where('status', FinancialMonthStatus::Draft)->first();

        if ($month === null) {
            throw new \RuntimeException('There is no open financial month.');
        }

        return DB::transaction(function () use ($month) {
            $totalIncome = (float) $month->movements()
                ->where('category', MovementCategory::Income)
                ->sum('amount');
            $totalExpenses = (float) $month->movements()
                ->where('category', MovementCategory::Expense)
                ->sum('amount');

            $result = FinancialMonthCalculator::calculate(
                totalIncome: $totalIncome,
                tf2TargetQuantity: (float) $month->tf2_target_quantity,
                tf2Price: (float) $month->tf2_price,
                totalExpenses: $totalExpenses,
                reinvestmentPercent: (float) $month->reinvestment_percent,
                emergencyPercent: (float) $month->emergency_percent,
                partnerOneShare: (float) $month->partner_one_share,
            );

            $this->generateTransfer($month, AccountType::Reinvestment, MovementCategory::ReinvestmentTransfer, $result->reinvestment);
            $this->generateTransfer($month, AccountType::Emergency, MovementCategory::EmergencyTransfer, $result->emergency);
            $this->generateDistribution($month, $month->partner_one_name, $result->partnerOne);
            $this->generateDistribution($month, $month->partner_two_name, $result->partnerTwo);

            $this->freezeSnapshot($month, $totalIncome, $totalExpenses, $result);

            $this->openNextDraft($month);

            return $month;
        });
    }

    /**
     * Transferência do Principal para uma caixa em dupla partida: débito no
     * Principal e crédito na caixa, mesmo valor.
     */
    private function generateTransfer(FinancialMonth $month, AccountType $fund, MovementCategory $category, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $this->writeMovement($month, AccountType::Principal, MovementDirection::Debit, $category, $amount, isGenerated: true);
        $this->writeMovement($month, $fund, MovementDirection::Credit, $category, $amount, isGenerated: true);
    }

    private function generateDistribution(FinancialMonth $month, ?string $partnerName, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $this->writeMovement(
            $month,
            AccountType::Principal,
            MovementDirection::Debit,
            MovementCategory::PartnerDistribution,
            $amount,
            isGenerated: true,
            description: $partnerName,
        );
    }

    private function freezeSnapshot(FinancialMonth $month, float $totalIncome, float $totalExpenses, FinancialMonthResult $result): void
    {
        $month->update([
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'tf2_reserve' => $result->tf2Reserve,
            'base_balance' => $result->base,
            'reinvestment_amount' => $result->reinvestment,
            'emergency_amount' => $result->emergency,
            'distributable' => $result->distributable,
            'partner_one_amount' => $result->partnerOne,
            'partner_two_amount' => $result->partnerTwo,
            'status' => FinancialMonthStatus::Closed,
            'closed_at' => now(),
        ]);
    }

    /**
     * Abre o próximo draft herdando taxas, split, nomes, preço e a meta de TF2
     * acrescida do incremento; os saldos de fecho viram movimentos `opening`.
     */
    private function openNextDraft(FinancialMonth $month): void
    {
        [$year, $monthNumber] = $this->nextYearMonth($month);

        $next = FinancialMonth::create([
            'year' => $year,
            'month' => $monthNumber,
            'status' => FinancialMonthStatus::Draft,
            'tf2_target_quantity' => $month->tf2_target_quantity + $month->tf2_increment,
            'tf2_increment' => $month->tf2_increment,
            'tf2_price' => $month->tf2_price,
            'reinvestment_percent' => $month->reinvestment_percent,
            'emergency_percent' => $month->emergency_percent,
            'partner_one_share' => $month->partner_one_share,
            'partner_one_name' => $month->partner_one_name,
            'partner_two_name' => $month->partner_two_name,
        ]);

        foreach ($this->closingBalances($month) as $accountValue => $balance) {
            if ($balance === 0.0) {
                continue;
            }

            $this->writeMovement(
                $next,
                AccountType::from($accountValue),
                $balance > 0 ? MovementDirection::Credit : MovementDirection::Debit,
                MovementCategory::Opening,
                abs($balance),
                isGenerated: false,
                description: 'Saldo de abertura',
            );
        }
    }

    /**
     * Saldo de fecho de cada conta = Σ créditos − Σ débitos dos movimentos do
     * mês, acumulado em centavos inteiros via `Money` para reconciliar exato.
     *
     * @return array<string, float>
     */
    private function closingBalances(FinancialMonth $month): array
    {
        $balances = [];
        foreach (AccountType::cases() as $account) {
            $balances[$account->value] = Money::zero();
        }

        foreach ($month->movements as $movement) {
            $amount = Money::fromReais((float) $movement->amount);
            $key = $movement->account_type->value;

            $balances[$key] = $movement->direction === MovementDirection::Credit
                ? $balances[$key]->plus($amount)
                : $balances[$key]->minus($amount);
        }

        return array_map(fn (Money $balance): float => $balance->toReais(), $balances);
    }

    /**
     * Ano/mês do próximo draft, virando o ano em dezembro.
     *
     * @return array{0: int, 1: int}
     */
    private function nextYearMonth(FinancialMonth $month): array
    {
        return $month->month === 12
            ? [$month->year + 1, 1]
            : [$month->year, $month->month + 1];
    }

    private function writeMovement(
        FinancialMonth $month,
        AccountType $account,
        MovementDirection $direction,
        MovementCategory $category,
        float $amount,
        bool $isGenerated,
        ?string $description = null,
    ): void {
        $month->movements()->create([
            'account_type' => $account,
            'direction' => $direction,
            'category' => $category,
            'amount' => $amount,
            'description' => $description,
            'occurred_at' => now()->toDateString(),
            'is_generated' => $isGenerated,
        ]);
    }
}
