<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Troca as constraints `unique` das tabelas com soft-delete por índices únicos
 * **parciais** (`WHERE deleted_at IS NULL`): a unicidade passa a valer só entre
 * as linhas vivas, então uma linha soft-deletada não bloqueia recriar um
 * registro com o mesmo valor.
 *
 * Um `unique(['year', 'month', 'deleted_at'])` não resolveria: Postgres (prod) e
 * SQLite (testes) tratam NULL como distinto, logo duas linhas ativas iguais
 * (`deleted_at` NULL nas duas) passariam pela constraint. Ambos suportam `WHERE`
 * em índice — o índice parcial é a forma correta nos dois bancos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_months', function (Blueprint $table) {
            $table->dropUnique('financial_months_year_month_unique');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique('suppliers_steam_id_unique');
        });

        DB::statement('CREATE UNIQUE INDEX financial_months_year_month_active_unique ON financial_months (year, month) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX suppliers_steam_id_active_unique ON suppliers (steam_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS financial_months_year_month_active_unique');
        DB::statement('DROP INDEX IF EXISTS suppliers_steam_id_active_unique');

        Schema::table('financial_months', function (Blueprint $table) {
            $table->unique(['year', 'month']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->unique('steam_id');
        });
    }
};
