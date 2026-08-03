<?php

namespace App\Services\Financial;

use App\Domain\Enums\AccountType;
use App\Domain\Enums\FinancialMonthStatus;
use App\Domain\Enums\MovementDirection;
use App\Domain\Financial\Money;
use App\Models\FinancialMonth;

/**
 * Lado de leitura do fechamento mensal (CQRS): hidrata a tela e deriva saldos.
 * Nenhuma conta guarda saldo próprio — o saldo é a soma dos movimentos do mês
 * (crédito soma, débito subtrai), acumulada em centavos inteiros via `Money`
 * para reconciliar exato. O lado de escrita vive nos UseCases, que gravam pelo
 * `MovementRecorder`.
 */
class FinancialMonthService
{
    /**
     * O fechamento em aberto — é nele que todo lançamento entra.
     *
     * Existe no máximo um `draft` por vez (ver docs/adr/0005), então "o mês
     * corrente" não é ambíguo. Não haver nenhum é estado excepcional — falta
     * rodar o bootstrap ou fechar o anterior abriu o próximo —, não um caso que
     * cada UseCase deva tratar linha a linha.
     */
    public function currentDraftOrFail(): FinancialMonth
    {
        $month = FinancialMonth::where('status', FinancialMonthStatus::Draft)->first();

        if ($month === null) {
            throw new \RuntimeException('There is no open financial month.');
        }

        return $month;
    }

    /**
     * Payload da página: o draft corrente (com movimentos e saldos) e o
     * histórico de meses fechados, do mais recente ao mais antigo.
     *
     * @return array{current: FinancialMonth|null, balances: array<string, float>|null, closed: \Illuminate\Support\Collection<int, FinancialMonth>}
     */
    public function overview(): array
    {
        $current = FinancialMonth::where('status', FinancialMonthStatus::Draft)
            ->with('movements')
            ->first();

        $closed = FinancialMonth::where('status', FinancialMonthStatus::Closed)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();

        return [
            'current' => $current,
            'balances' => $current ? $this->accountBalances($current) : null,
            'closed' => $closed,
        ];
    }

    /**
     * Saldo de cada uma das três contas do mês, em reais.
     *
     * @return array<string, float> ex: ['principal' => 1150.00, 'reinvestment' => 330.00, 'emergency' => 132.00]
     */
    public function accountBalances(FinancialMonth $month): array
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
}
