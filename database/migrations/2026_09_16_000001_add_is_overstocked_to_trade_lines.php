<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca da linha cujo jogo estava encalhado no estoque quando ela nasceu ou
 * trocou de jogo (ver App\Domain\Trades\OverstockPolicy e docs/adr/0013).
 *
 * Sem backfill: a marca registra o que a regra viu no momento da escrita, e
 * recalcular as linhas antigas com o estoque de hoje gravaria um fato que
 * nunca aconteceu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_lines', function (Blueprint $table) {
            $table->boolean('is_overstocked')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('trade_lines', function (Blueprint $table) {
            $table->dropColumn('is_overstocked');
        });
    }
};
