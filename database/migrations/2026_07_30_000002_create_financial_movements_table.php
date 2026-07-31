<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_month_id')
                ->constrained('financial_months')
                ->cascadeOnDelete();

            $table->string('account_type');  // App\Domain\Enums\AccountType
            $table->string('direction');     // App\Domain\Enums\MovementDirection
            $table->string('category');      // App\Domain\Enums\MovementCategory

            // Sempre positivo; a direção decide se soma ou subtrai do saldo.
            $table->decimal('amount', 12, 2);
            $table->string('description')->nullable();
            $table->date('occurred_at');

            // Detalhe da compra real de TF2 (category = tf2_purchase): qtd × preço, só para visualização.
            $table->decimal('quantity', 10, 2)->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();

            // Movimento criado pelo fechamento (transferências/distribuições). A reabertura desfaz só estes.
            $table->boolean('is_generated')->default(false);

            $table->timestamps();

            $table->index('account_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_movements');
    }
};
