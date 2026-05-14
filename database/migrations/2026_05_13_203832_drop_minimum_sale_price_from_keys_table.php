<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('keys', function (Blueprint $table) {
            $table->dropColumn('minimum_sale_price');
        });
    }

    public function down(): void
    {
        Schema::table('keys', function (Blueprint $table) {
            $table->decimal('minimum_sale_price', total: 8, places: 2)->nullable();
        });
    }
};
