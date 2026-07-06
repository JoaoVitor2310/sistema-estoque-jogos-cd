<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keys', function (Blueprint $table) {
            // O nome legado da FK (criada antes do rename da tabela) só existe no PostgreSQL.
            // SQLite não suporta dropForeign por nome — usa dropForeign por coluna.
            if (DB::getDriverName() === 'pgsql') {
                $table->dropForeign('venda_chave_trocas_id_fornecedor_foreign');
            } else {
                $table->dropForeign(['supplier_id']);
            }

            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('keys', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);

            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->restrictOnDelete();
        });
    }
};
