<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Acrescenta `trades.request_uuid`: o id do pedido que criou a trade pela API
 * (`POST /api/trades`), mandado pelo price_researcher.
 *
 * É a chave de idempotência da criação: o mesmo pedido repetido (timeout,
 * nova tentativa do job) devolve a trade que já existe em vez de criar outra.
 * Nulo nas trades que não vêm desse pedido.
 *
 * Índice único **parcial** (`WHERE deleted_at IS NULL`), como o de
 * `suppliers.steam_id`: a trade soft-deletada não segura o id. Postgres e
 * SQLite tratam NULL como distinto, então as trades sem pedido não colidem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->uuid('request_uuid')->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX trades_request_uuid_active_unique ON trades (request_uuid) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS trades_request_uuid_active_unique');

        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn('request_uuid');
        });
    }
};
