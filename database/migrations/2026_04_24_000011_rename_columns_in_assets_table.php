<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->renameColumn('preco_euro', 'price_euro');
            $table->renameColumn('preco_dolar', 'price_dollar');
            $table->renameColumn('preco_real', 'price_brl');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->renameColumn('price_euro', 'preco_euro');
            $table->renameColumn('price_dollar', 'preco_dolar');
            $table->renameColumn('price_brl', 'preco_real');
        });
    }
};
