<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('venda_chave_trocas', function (Blueprint $table) {
            $table->string('region')->after('nomeJogo')->nullable()->comment('Região do jogo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('venda_chave_trocas', function (Blueprint $table) {
            $table->dropColumn('region');
        });
    }
};
