<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('suppliers')
            ->whereDate('created_at', '>=', '2026-06-12')
            ->pluck('id');

        DB::table('keys')
            ->whereIn('supplier_id', $ids)
            ->update(['supplier_id' => null]);

        DB::table('suppliers')
            ->whereIn('id', $ids)
            ->delete();
    }

    public function down(): void
    {
        // Irreversível — dados de prospects da Steam Trades, sem valor de restaurar
    }
};
