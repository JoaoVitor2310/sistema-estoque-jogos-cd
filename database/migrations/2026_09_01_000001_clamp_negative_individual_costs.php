<?php

use App\Domain\Pricing\ProfitCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Zera os custos individuais negativos que já estão na base e refaz os lucros deles.
 *
 * Custo negativo é dado inválido — o cálculo agora o saneia (ProfitCalculator::normalizeCost)
 * e o modelo o barra na escrita, mas as linhas antigas continuariam com lucro e margem
 * errados: uma venda lucrativa aparecia com margem de -2100% porque o custo negativo vira
 * divisor negativo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // withTrashed: uma key apagada continua contando no histórico financeiro
        $keys = DB::table('keys')->where('individual_cost', '<', 0)->get();

        foreach ($keys as $key) {
            $cost = ProfitCalculator::normalizeCost((float) $key->individual_cost);

            $purchaseProfit = ProfitCalculator::purchaseProfit((float) $key->simulated_income, $cost);
            $saleProfit = ProfitCalculator::saleProfit(
                $key->sold_price === null ? null : (float) $key->sold_price,
                $cost,
            );

            DB::table('keys')->where('id', $key->id)->update([
                'individual_cost' => $cost,
                'purchase_profit' => $purchaseProfit,
                'purchase_profit_percent' => ProfitCalculator::purchaseProfitPercent($purchaseProfit, $cost),
                'sale_profit' => $saleProfit ?? 0,
                'sale_profit_percent' => ProfitCalculator::saleProfitPercent($saleProfit, $cost) ?? 0,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Sem volta: o valor negativo original era inválido e não vale restaurar.
    }
};
