<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venda_chave_trocas', function ($table) {
            $table->renameColumn('tipo_formato', 'key_format');
            $table->renameColumn('tipo_reclamacao', 'claim_type');
            $table->renameColumn('plataforma_venda', 'sell_platform');
        });
    }

    public function down(): void
    {
        Schema::table('venda_chave_trocas', function ($table) {
            $table->renameColumn('key_format', 'tipo_formato');
            $table->renameColumn('claim_type', 'tipo_reclamacao');
            $table->renameColumn('sell_platform', 'plataforma_venda');
        });
    }
};
