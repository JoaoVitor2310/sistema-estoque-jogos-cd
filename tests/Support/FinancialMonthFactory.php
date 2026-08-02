<?php

namespace Tests\Support;

use App\Domain\Enums\FinancialMonthStatus;
use App\Models\FinancialMonth;
use Illuminate\Support\Facades\DB;

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
