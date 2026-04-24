<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('fornecedor', 'suppliers');

        Schema::table('keys', function (Blueprint $table) {
            $table->renameColumn('id_fornecedor', 'supplier_id');
        });
    }

    public function down(): void
    {
        Schema::table('keys', function (Blueprint $table) {
            $table->renameColumn('supplier_id', 'id_fornecedor');
        });

        Schema::rename('suppliers', 'fornecedor');
    }
};
