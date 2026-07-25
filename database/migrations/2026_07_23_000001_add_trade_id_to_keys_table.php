<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Coluna primeiro, para existir antes de a FK ser adicionada.
        Schema::table('keys', function (Blueprint $table) {
            $table->unsignedBigInteger('trade_id')->nullable();
            $table->index('trade_id');
        });

        // FK com nullOnDelete — apagar uma trade não apaga as keys, só zera o vínculo.
        // Adicionar FK a uma tabela existente funciona no SQLite (Laravel 11 recria a
        // tabela) e no PostgreSQL — mesmo padrão da migration de FK do supplier.
        Schema::table('keys', function (Blueprint $table) {
            $table->foreign('trade_id')
                ->references('id')
                ->on('trades')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('keys', function (Blueprint $table) {
            $table->dropForeign(['trade_id']);
            $table->dropIndex(['trade_id']);
            $table->dropColumn('trade_id');
        });
    }
};
