<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fornecedor', function (Blueprint $table) {
            $table->renameColumn('perfilOrigem', 'supplier_url');
        });
    }

    public function down(): void
    {
        Schema::table('fornecedor', function (Blueprint $table) {
            $table->renameColumn('supplier_url', 'perfilOrigem');
        });
    }
};
