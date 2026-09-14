<?php

use App\Domain\Enums\PurchaseChannel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canal de compra da trade (ver App\Domain\Enums\PurchaseChannel).
 *
 * Toda trade existente nasce como trade com fornecedor — as compras diretas que
 * hoje carregam o nome do bundle no lugar do fornecedor são reclassificadas à
 * mão pela aba de Trades.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->string('purchase_channel', 32)->default(PurchaseChannel::SupplierTrade->value);
            $table->foreignId('bundle_id')->nullable()->constrained('bundles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bundle_id');
            $table->dropColumn('purchase_channel');
        });
    }
};
