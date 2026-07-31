<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_months', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status')->default('draft'); // App\Domain\Enums\FinancialMonthStatus

            // Inputs do mês — editáveis enquanto draft, congelados ao fechar.
            $table->unsignedInteger('tf2_target_quantity')->default(0);
            $table->unsignedInteger('tf2_increment')->default(10); // espelha FinancialMonthDefaults::TF2_MONTHLY_INCREMENT
            $table->decimal('tf2_price', 10, 2)->default(0);

            // Taxas e sócios — carregam do mês anterior; defaults espelham FinancialMonthDefaults.
            $table->decimal('reinvestment_percent', 6, 4)->default(0.2000);
            $table->decimal('emergency_percent', 6, 4)->default(0.1000);
            $table->decimal('partner_one_share', 6, 4)->default(0.5000);
            $table->string('partner_one_name')->nullable();
            $table->string('partner_two_name')->nullable();

            // Snapshot da cascata — preenchido no fechamento (null enquanto draft).
            $table->decimal('total_income', 12, 2)->nullable();
            $table->decimal('total_expenses', 12, 2)->nullable();
            $table->decimal('tf2_reserve', 12, 2)->nullable();
            $table->decimal('base_balance', 12, 2)->nullable();
            $table->decimal('reinvestment_amount', 12, 2)->nullable();
            $table->decimal('emergency_amount', 12, 2)->nullable();
            $table->decimal('distributable', 12, 2)->nullable();
            $table->decimal('partner_one_amount', 12, 2)->nullable();
            $table->decimal('partner_two_amount', 12, 2)->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            // Um fechamento por mês/ano.
            $table->unique(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_months');
    }
};
