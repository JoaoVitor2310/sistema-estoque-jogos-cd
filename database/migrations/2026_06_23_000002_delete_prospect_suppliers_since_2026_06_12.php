<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('suppliers')
            ->whereDate('created_at', '>=', '2026-06-12')
            ->delete();
    }

    public function down(): void
    {
        // Irreversível — dados de prospects da Steam Trades, sem valor de restaurar
    }
};
