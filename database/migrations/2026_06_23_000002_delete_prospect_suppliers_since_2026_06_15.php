<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Deleta apenas prospects sem nenhuma key associada.
        // Suppliers com keys vinculadas são fornecedores reais — não remover.
        DB::table('suppliers')
            ->whereDate('created_at', '>=', '2026-06-15')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('keys')
                    ->whereColumn('keys.supplier_id', 'suppliers.id');
            })
            ->delete();
    }

    public function down(): void
    {
        // Irreversível — dados de prospects da Steam Trades, sem valor de restaurar
    }
};
