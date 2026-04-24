<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venda_chave_trocas', function (Blueprint $table) {
            $table->renameColumn('nomeJogo', 'game_name');
            $table->renameColumn('chaveRecebida', 'key_code');
            $table->renameColumn('idGamivo', 'gamivo_id');
            $table->renameColumn('plataformaIdentificada', 'identified_platform');
            $table->renameColumn('repetido', 'is_duplicate');
            $table->renameColumn('perfilOrigem', 'supplier_url');
            $table->renameColumn('qtdTF2', 'tf2_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('venda_chave_trocas', function (Blueprint $table) {
            $table->renameColumn('game_name', 'nomeJogo');
            $table->renameColumn('key_code', 'chaveRecebida');
            $table->renameColumn('gamivo_id', 'idGamivo');
            $table->renameColumn('identified_platform', 'plataformaIdentificada');
            $table->renameColumn('is_duplicate', 'repetido');
            $table->renameColumn('supplier_url', 'perfilOrigem');
            $table->renameColumn('tf2_quantity', 'qtdTF2');
        });
    }
};
