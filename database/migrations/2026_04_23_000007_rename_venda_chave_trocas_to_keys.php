<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('venda_chave_trocas', 'keys');
    }

    public function down(): void
    {
        Schema::rename('keys', 'venda_chave_trocas');
    }
};
