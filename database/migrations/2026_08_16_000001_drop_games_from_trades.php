<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Último passo da normalização de `trades.games` em `trade_lines`.
     *
     * A coluna já era nulável e não tinha leitor nem escritor — o backfill da
     * migration `2026_08_15_000001` converteu tudo, e desde então a aba, a busca
     * e o import trabalham sobre `trade_lines`. Só sobrevivia para manter o
     * rollback barato enquanto a conversão não era conferida.
     *
     * `App\Domain\Trades\LegacyTradeLine` **fica**: a migration de backfill a
     * referencia, e um `migrate:fresh` ainda replaya aquela passada antes de
     * chegar aqui.
     */
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn('games');
        });
    }

    /**
     * Recria a coluna vazia. O conteúdo não volta — as linhas convertidas vivem
     * em `trade_lines`, e é de lá que uma reconstrução sairia.
     */
    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->json('games')->nullable();
        });
    }
};
