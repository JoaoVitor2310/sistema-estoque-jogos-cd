<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            // Marca que as keys da trade já foram importadas. A trade não é excluída
            // ao importar, para manter keys.trade_id válido (ver docs/adr/0004);
            // TradeService::paginate esconde importadas no default (view=open).
            $table->boolean('is_imported')->default(false)->after('message_sent');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn('is_imported');
        });
    }
};
