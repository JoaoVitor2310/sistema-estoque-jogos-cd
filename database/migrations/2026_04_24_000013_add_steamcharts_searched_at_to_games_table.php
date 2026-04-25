<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona steamcharts_searched_at à tabela games.
 *
 * Permite distinguir dois estados distintos que antes eram indistinguíveis:
 *   - steamcharts_id IS NULL + steamcharts_searched_at IS NULL  → nunca buscado
 *   - steamcharts_id IS NULL + steamcharts_searched_at NOT NULL → buscado, não encontrado
 *
 * Sem essa coluna, o cron reprocessaria indefinidamente jogos
 * que simplesmente não existem no Steamcharts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->timestamp('steamcharts_searched_at')->nullable()->after('steamcharts_id');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('steamcharts_searched_at');
        });
    }
};
