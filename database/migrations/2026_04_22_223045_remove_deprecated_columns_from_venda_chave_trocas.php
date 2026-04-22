<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venda_chave_trocas', function (Blueprint $table) {
            $table->dropColumn([
                'notaMetacritic',
                'isSteam',
                'randomClassificationG2A',
                'randomClassificationKinguin',
                'precoVenda',
                'incomeReal',
                'chaveEntregue',
                'vendido',
                'leiloes',
                'quantidade',
                'devolucoes',
            ]);
        });

        // Drop FK constraints before dropping columns (PostgreSQL requires this)
        Schema::table('venda_chave_trocas', function (Blueprint $table) {
            $table->dropForeign(['id_leilao_g2a']);
            $table->dropForeign(['id_leilao_gamivo']);
            $table->dropForeign(['id_leilao_kinguin']);
            $table->dropColumn(['id_leilao_g2a', 'id_leilao_gamivo', 'id_leilao_kinguin']);
        });
    }

    public function down(): void
    {
        Schema::table('venda_chave_trocas', function (Blueprint $table) {
            $table->integer('notaMetacritic')->default(0);
            $table->boolean('isSteam')->nullable();
            $table->string('randomClassificationG2A')->nullable();
            $table->string('randomClassificationKinguin')->nullable();
            $table->decimal('precoVenda', 8, 2)->nullable();
            $table->decimal('incomeReal', 8, 2)->nullable();
            $table->string('chaveEntregue')->nullable();
            $table->boolean('vendido')->nullable();
            $table->integer('leiloes')->default(0);
            $table->integer('quantidade')->default(0);
            $table->boolean('devolucoes')->nullable();
            $table->integer('id_leilao_g2a')->default(1);
            $table->integer('id_leilao_gamivo')->default(1);
            $table->integer('id_leilao_kinguin')->default(1);
        });
    }
};
