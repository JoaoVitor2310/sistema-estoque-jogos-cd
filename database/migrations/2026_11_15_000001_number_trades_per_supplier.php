<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Número da trade por supplier: a primeira trade de um supplier é 1, a segunda
 * 2. É o que o supplier digita no chat para escolher a trade — o `uuid` é
 * longo demais e o `id` contaria quantas trades já fizemos.
 *
 * - `trades.supplier_trade_number`: nulo quando a trade não tem supplier.
 *   Índice único em (supplier_id, número), **contando as apagadas**: número
 *   dado não volta.
 * - `suppliers.last_trade_number`: o contador. O próximo número sai dele, e
 *   não do maior número das trades: com o maior, a trade de número mais alto
 *   que mudasse de supplier faria o próximo repetir o número dela.
 *
 * Backfill: as trades de cada supplier, apagadas incluídas, numeradas por data
 * de criação e depois id; o contador fica no último número dado. Em PHP, e não
 * com função de janela, para rodar igual no Postgres e no SQLite dos testes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->unsignedInteger('supplier_trade_number')->nullable();
            $table->unique(['supplier_id', 'supplier_trade_number'], 'trades_supplier_trade_number_unique');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedInteger('last_trade_number')->default(0);
        });

        $counters = [];

        // `created_at` nulo por último, explícito: Postgres e SQLite ordenam
        // nulos em pontas opostas.
        DB::table('trades')
            ->whereNotNull('supplier_id')
            ->orderBy('supplier_id')
            ->orderByRaw('CASE WHEN created_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('created_at')
            ->orderBy('id')
            ->select(['id', 'supplier_id'])
            ->get()
            ->each(function ($trade) use (&$counters) {
                $number = ($counters[$trade->supplier_id] ?? 0) + 1;
                $counters[$trade->supplier_id] = $number;

                DB::table('trades')->where('id', $trade->id)->update(['supplier_trade_number' => $number]);
            });

        foreach ($counters as $supplierId => $last) {
            DB::table('suppliers')->where('id', $supplierId)->update(['last_trade_number' => $last]);
        }
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropUnique('trades_supplier_trade_number_unique');
            $table->dropColumn('supplier_trade_number');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('last_trade_number');
        });
    }
};
