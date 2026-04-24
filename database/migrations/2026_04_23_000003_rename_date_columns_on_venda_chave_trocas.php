<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venda_chave_trocas', function (Blueprint $table) {
            $table->renameColumn('dataVenda', 'listed_at');
            $table->renameColumn('dataVendida', 'sold_at');
            $table->renameColumn('dataAdquirida', 'acquired_at');
            $table->renameColumn('dataExpiracao', 'expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('venda_chave_trocas', function (Blueprint $table) {
            $table->renameColumn('listed_at', 'dataVenda');
            $table->renameColumn('sold_at', 'dataVendida');
            $table->renameColumn('acquired_at', 'dataAdquirida');
            $table->renameColumn('expires_at', 'dataExpiracao');
        });
    }
};
