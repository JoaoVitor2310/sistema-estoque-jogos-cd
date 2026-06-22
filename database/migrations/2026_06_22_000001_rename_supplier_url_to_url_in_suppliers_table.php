<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserva dados dos suppliers criados pelo fluxo antigo de importação de keys
        DB::statement('UPDATE suppliers SET url = supplier_url WHERE url IS NULL');

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropIndex('fornecedor_perfilorigem_index');
            $table->dropColumn('supplier_url');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('supplier_url')->nullable()->after('id');
        });

        DB::statement('UPDATE suppliers SET supplier_url = url');
    }
};
