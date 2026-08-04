<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_movements', function (Blueprint $table) {
            // App\Domain\Enums\ExpenseCategory — só para category = expense.
            $table->string('expense_category')->nullable()->after('category');
            // App\Domain\Enums\IncomeCategory — só para category = income.
            $table->string('income_category')->nullable()->after('expense_category');
        });
    }

    public function down(): void
    {
        Schema::table('financial_movements', function (Blueprint $table) {
            $table->dropColumn(['expense_category', 'income_category']);
        });
    }
};
