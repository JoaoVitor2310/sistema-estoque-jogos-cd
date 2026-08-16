<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `trades.games` deixou de ser escrita — as linhas vivem em `trade_lines`.
     * A coluna é `NOT NULL` sem default, então uma trade nova não entraria mais.
     *
     * Aqui ela só afrouxa: continua no banco, com o dado antigo intacto, para o
     * rollback seguir barato até a conversão ser conferida. Quem a derruba é a
     * migration seguinte.
     */
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->json('games')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Voltar a NOT NULL exige um default para as linhas gravadas sem games.
        Schema::table('trades', function (Blueprint $table) {
            $table->json('games')->nullable(false)->default('[]')->change();
        });
    }
};
