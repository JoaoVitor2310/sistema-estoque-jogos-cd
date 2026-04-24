<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venda_chave_trocas', function (Blueprint $table) {
            $table->renameColumn('lucroRS', 'purchase_profit');
            $table->renameColumn('lucroPercentual', 'purchase_profit_percent');
            $table->renameColumn('lucroVendaRS', 'sale_profit');
            $table->renameColumn('lucroVendaPercentual', 'sale_profit_percent');
            $table->renameColumn('valorVendido', 'sold_price');
            $table->renameColumn('valorPagoIndividual', 'individual_cost');
            $table->renameColumn('valorPagoTotal', 'total_paid');
            $table->renameColumn('precoCliente', 'market_price');
            $table->renameColumn('minimoParaVenda', 'minimum_sale_price');
            $table->renameColumn('incomeSimulado', 'simulated_income');
        });
    }

    public function down(): void
    {
        Schema::table('venda_chave_trocas', function (Blueprint $table) {
            $table->renameColumn('purchase_profit', 'lucroRS');
            $table->renameColumn('purchase_profit_percent', 'lucroPercentual');
            $table->renameColumn('sale_profit', 'lucroVendaRS');
            $table->renameColumn('sale_profit_percent', 'lucroVendaPercentual');
            $table->renameColumn('sold_price', 'valorVendido');
            $table->renameColumn('individual_cost', 'valorPagoIndividual');
            $table->renameColumn('total_paid', 'valorPagoTotal');
            $table->renameColumn('market_price', 'precoCliente');
            $table->renameColumn('minimum_sale_price', 'minimoParaVenda');
            $table->renameColumn('simulated_income', 'incomeSimulado');
        });
    }
};
