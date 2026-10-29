<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renomeia `trades.delivery_uuid` para `uuid`.
 *
 * O uuid deixou de ser só o endereço da entrega: é a identidade da trade entre
 * os sistemas (chatbot, price_researcher), e a entrega é uma rota que o usa.
 * Os valores não mudam, então todo link de entrega já enviado continua valendo.
 * O índice único é renomeado junto para o nome seguir a coluna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->renameColumn('delivery_uuid', 'uuid');
        });

        // Em chamada separada: no SQLite o índice é recriado a partir das colunas
        // lidas no momento da compilação, e no mesmo Blueprint ele voltaria
        // apontando para `delivery_uuid`, que já não existe.
        Schema::table('trades', function (Blueprint $table) {
            $table->renameIndex('trades_delivery_uuid_unique', 'trades_uuid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->renameColumn('uuid', 'delivery_uuid');
        });

        Schema::table('trades', function (Blueprint $table) {
            $table->renameIndex('trades_uuid_unique', 'trades_delivery_uuid_unique');
        });
    }
};
