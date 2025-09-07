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
            $table->enum('dont_sell', ['yes', 'no'])->default('no')->after('tipo_reclamacao_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('venda_chave_trocas', function (Blueprint $table) {
            $table->dropColumn('dont_sell');
        });
    }
};
