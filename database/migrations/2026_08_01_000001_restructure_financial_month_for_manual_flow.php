<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reestrutura o fechamento mensal para o fluxo manual (ver docs/adr/0005).
 *
 * O fechamento deixa de calcular uma cascata, então o snapshot dela morre — os
 * totais do mês passam a ser derivados dos movimentos. A meta de TF2 sai do mês
 * e passa a viver no próprio movimento de alocação (que já tem `quantity` e
 * `unit_price`). Sócios deixam de ter nome cadastrado: são identificados por
 * posição no movimento de distribuição.
 *
 * As colunas novas em `financial_movements`:
 *  - `group_id`     : liga as linhas nascidas do mesmo lançamento (a transferência
 *                     grava débito + crédito), para que a exclusão remova o par junto
 *  - `partner_slot` : de qual sócio (1 ou 2) é o débito de distribuição
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_months', function (Blueprint $table) {
            $table->dropColumn([
                // Snapshot da cascata removida.
                'total_income',
                'total_expenses',
                'tf2_reserve',
                'base_balance',
                'reinvestment_amount',
                'emergency_amount',
                'distributable',
                'partner_one_amount',
                'partner_two_amount',
                // Meta de TF2 passou para o movimento `tf2_allocation`.
                'tf2_target_quantity',
                'tf2_price',
                'tf2_increment',
                // Sócios são identificados por posição, não por nome.
                'partner_one_name',
                'partner_two_name',
            ]);
        });

        Schema::table('financial_movements', function (Blueprint $table) {
            $table->uuid('group_id')->nullable()->after('financial_month_id');
            $table->unsignedTinyInteger('partner_slot')->nullable()->after('unit_price');

            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::table('financial_months', function (Blueprint $table) {
            $table->decimal('total_income', 12, 2)->nullable();
            $table->decimal('total_expenses', 12, 2)->nullable();
            $table->decimal('tf2_reserve', 12, 2)->nullable();
            $table->decimal('base_balance', 12, 2)->nullable();
            $table->decimal('reinvestment_amount', 12, 2)->nullable();
            $table->decimal('emergency_amount', 12, 2)->nullable();
            $table->decimal('distributable', 12, 2)->nullable();
            $table->decimal('partner_one_amount', 12, 2)->nullable();
            $table->decimal('partner_two_amount', 12, 2)->nullable();
            $table->unsignedInteger('tf2_target_quantity')->default(0);
            $table->decimal('tf2_price', 10, 2)->default(0);
            $table->unsignedInteger('tf2_increment')->default(10);
            $table->string('partner_one_name')->nullable();
            $table->string('partner_two_name')->nullable();
        });

        Schema::table('financial_movements', function (Blueprint $table) {
            $table->dropIndex(['group_id']);
            $table->dropColumn(['group_id', 'partner_slot']);
        });
    }
};
