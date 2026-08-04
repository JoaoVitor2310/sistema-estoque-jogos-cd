<?php

namespace Tests\Support;

use App\Domain\Enums\AccountType;
use App\Domain\Enums\FinancialMonthStatus;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use App\Models\FinancialMonth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds compartilhados do domínio financeiro.
 *
 * Namespaced de propósito: helpers soltos no topo de um arquivo de teste são
 * promovidos pelo Pest ao namespace global, e nomes genéricos (`draftMonth`)
 * colidem entre arquivos.
 */
final class FinancialMonthFactory
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function draft(array $overrides = []): FinancialMonth
    {
        return self::create(['status' => FinancialMonthStatus::Draft->value] + $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function closed(array $overrides = []): FinancialMonth
    {
        return self::create([
            'status' => FinancialMonthStatus::Closed->value,
            'closed_at' => now(),
        ] + $overrides);
    }

    /**
     * Dá saldo inicial a uma conta, para os testes que precisam de dinheiro em
     * caixa antes do lançamento sob teste (ex: transferir uma % do Principal).
     */
    public static function credit(FinancialMonth $month, AccountType $account, float $amount): void
    {
        $month->movements()->create([
            'account_type' => $account,
            'direction' => MovementDirection::Credit,
            'category' => MovementCategory::Opening,
            'amount' => $amount,
            'occurred_at' => now()->toDateString(),
            'is_generated' => false,
        ]);
    }

    /**
     * Aloca verba de TF2 num mês qualquer, gravando as **duas** pernas com o
     * mesmo `group_id` — como faz o `MovementRecorder`.
     *
     * Existe porque o `RecordTf2AllocationUseCase` só atinge o draft corrente, e
     * há testes que precisam semear a alocação num mês já fechado.
     */
    public static function allocateTf2(FinancialMonth $month, float $quantity, float $unitPrice): void
    {
        $groupId = (string) Str::uuid();

        $legs = [
            [AccountType::Principal, MovementDirection::Debit],
            [AccountType::Tf2, MovementDirection::Credit],
        ];

        foreach ($legs as [$account, $direction]) {
            $month->movements()->create([
                'group_id' => $groupId,
                'account_type' => $account,
                'direction' => $direction,
                'category' => MovementCategory::Tf2Allocation,
                'amount' => $quantity * $unitPrice,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'occurred_at' => now()->toDateString(),
                'is_generated' => false,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private static function create(array $overrides): FinancialMonth
    {
        $id = DB::table('financial_months')->insertGetId($overrides + [
            'year' => 2026,
            'month' => 7,
            'reinvestment_percent' => 0.20,
            'emergency_percent' => 0.10,
            'partner_one_share' => 0.50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return FinancialMonth::findOrFail($id);
    }
}
