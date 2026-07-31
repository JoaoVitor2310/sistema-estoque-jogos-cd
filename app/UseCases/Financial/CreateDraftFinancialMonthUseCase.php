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
 * O estado de abertura é informado à mão: meta e preço de TF2, taxas, sócios e
 * os saldos iniciais das três contas. Cada saldo vira um movimento `opening`
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
    /**
     * @param  array{
     *     year?: int|null,
     *     month?: int|null,
     *     tf2TargetQuantity?: int|null,
     *     tf2Price?: float|null,
     *     reinvestmentPercent?: float|null,
     *     emergencyPercent?: float|null,
     *     partnerOneShare?: float|null,
     *     partnerOneName?: string|null,
     *     partnerTwoName?: string|null,
     *     openingBalances?: array<string, float>
     * }  $data
     */
    public function execute(array $data): FinancialMonth
    {
        if (FinancialMonth::exists()) {
            throw new \RuntimeException('The first financial month has already been opened.');
        }

        return DB::transaction(function () use ($data) {
            $month = FinancialMonth::create([
                'year' => $data['year'] ?? (int) now()->year,
                'month' => $data['month'] ?? (int) now()->month,
                'status' => FinancialMonthStatus::Draft,
                'tf2_target_quantity' => $data['tf2TargetQuantity'] ?? 0,
                'tf2_increment' => FinancialMonthDefaults::TF2_MONTHLY_INCREMENT,
                'tf2_price' => $data['tf2Price'] ?? 0,
                'reinvestment_percent' => $data['reinvestmentPercent'] ?? FinancialMonthDefaults::REINVESTMENT_PERCENT,
                'emergency_percent' => $data['emergencyPercent'] ?? FinancialMonthDefaults::EMERGENCY_PERCENT,
                'partner_one_share' => $data['partnerOneShare'] ?? FinancialMonthDefaults::PARTNER_ONE_SHARE,
                'partner_one_name' => $data['partnerOneName'] ?? null,
                'partner_two_name' => $data['partnerTwoName'] ?? null,
            ]);

            $this->recordOpeningBalances($month, $data['openingBalances'] ?? []);

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
