<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona soft-delete a um conjunto curado de tabelas — registros de negócio
 * cujo apagamento acidental é caro de reverter. Tabelas de lookup/config
 * (`fees`, `assets`, `authorized_users`), o pivot `bundle_games` e `users`
 * ficam de fora de propósito (ver docs/adr/0011).
 */
return new class extends Migration
{
    private const TABLES = [
        'keys',
        'trades',
        'suppliers',
        'games',
        'bundles',
        'financial_months',
        'financial_movements',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
