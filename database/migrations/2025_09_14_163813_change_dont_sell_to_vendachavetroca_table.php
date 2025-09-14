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
            // Coluna dont_sell será "in_bundle"
            $table->renameColumn('dont_sell', 'in_bundle');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('venda_chave_trocas', function (Blueprint $table) {
            $table->renameColumn('in_bundle', 'dont_sell');
        });
    }
};
