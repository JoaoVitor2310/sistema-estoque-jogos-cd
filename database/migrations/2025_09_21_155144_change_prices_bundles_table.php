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
        Schema::table('bundles', function (Blueprint $table) {
            $table->renameColumn('price_tf2', 'minimum_price_tf2');
            $table->renameColumn('price_euro', 'price_dolar');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->renameColumn('minimum_price_tf2', 'price_tf2');
            $table->renameColumn('price_dolar', 'price_euro');
        });
    }
};
