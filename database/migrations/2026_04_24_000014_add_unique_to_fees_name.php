<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona constraint UNIQUE ao campo name da tabela fees.
 *
 * A ausência dessa constraint permitia inserir registros duplicados via
 * Seeder ou chamadas diretas, corrompendo os cálculos de taxa silenciosamente
 * (pluck() retorna o primeiro match, ignorando os demais).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fees', function (Blueprint $table) {
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('fees', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });
    }
};
