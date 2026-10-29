<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove o `initial_offer_pct` do supplier.
 *
 * O lucro da primeira oferta passou a vir da categoria do supplier
 * (`SupplierCategory::initialProfitPercent`), e a coluna — que nenhum código
 * lia — seria uma segunda fonte para o mesmo número.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('initial_offer_pct');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedSmallInteger('initial_offer_pct')->nullable()->default(100)->after('region');
        });
    }
};
