<?php

namespace App\UseCases\Financial;

use App\Domain\Enums\AccountType;
use App\Domain\Enums\FinancialMonthStatus;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Domain\Financial\FinancialMonthDefaults;
use App\Models\FinancialMonth;
use Illuminate\Support\Facades\DB;

/**
 * Abre o primeiro fechamento mensal (bootstrap).
 *
 * O estado de abertura é informado à mão: os saldos iniciais das quatro contas
 * e as porcentagens de prefill. Cada saldo vira um movimento `opening`
 * (crédito, manual) — a conta não guarda saldo próprio, ele é a soma dos
 * movimentos. Meses seguintes nascem do fechamento anterior (carry-forward),
 * não por aqui.
 *
 * Caminho exclusivo do bootstrap: recusa se já existir qualquer fechamento
 * (aberto ou fechado). Do segundo mês em diante, o próximo draft nasce só do
 * fechamento anterior (carry-forward), nunca por aqui.
 */
class CreateDraftFinancialMonthUseCase
{
    public function execute(BootstrapFinancialMonthData $data): FinancialMonth
    {
        if (FinancialMonth::exists()) {
            throw new \RuntimeException('The first financial month has already been opened.');
        }

        return DB::transaction(function () use ($data) {
            $month = FinancialMonth::create([
                'year' => $data->year ?? (int) now()->year,
                'month' => $data->month ?? (int) now()->month,
                'status' => FinancialMonthStatus::Draft,
                'reinvestment_percent' => $data->reinvestmentPercent ?? FinancialMonthDefaults::REINVESTMENT_PERCENT,
                'emergency_percent' => $data->emergencyPercent ?? FinancialMonthDefaults::EMERGENCY_PERCENT,
                'partner_one_share' => $data->partnerOneShare ?? FinancialMonthDefaults::PARTNER_ONE_SHARE,
            ]);

            $this->recordOpeningBalances($month, $data->openingBalances);

            return $month;
        });
    }

    /**
     * Registra o saldo inicial de cada conta como um movimento `opening`.
     * Só cria movimento para saldo diferente de zero — conta vazia não gera ruído.
     *
     * @param  array<string, float>  $balances
     */
    private function recordOpeningBalances(FinancialMonth $month, array $balances): void
    {
        foreach (AccountType::cases() as $account) {
            $amount = (float) ($balances[$account->value] ?? 0);

            if ($amount === 0.0) {
                continue;
            }

            $month->movements()->create([
                'account_type' => $account,
                'direction' => MovementDirection::Credit,
                'category' => MovementCategory::Opening,
                'amount' => $amount,
                'description' => 'Saldo de abertura',
                'occurred_at' => now()->toDateString(),
                'is_generated' => false,
            ]);
        }
    }
}
