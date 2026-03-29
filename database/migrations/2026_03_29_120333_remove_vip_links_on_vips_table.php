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
        Schema::table('vips', function (Blueprint $table) {
            $table->dropColumn('first_link');
            $table->dropColumn('second_link');
            $table->dropColumn('third_link');
            $table->dropColumn('steam_link');
            $table->dropColumn('result');
            $table->dropColumn('result_at');
            $table->string('id_steam')->after('name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vips', function (Blueprint $table) {
            $table->string('first_link')->nullable();
            $table->string('second_link')->nullable();
            $table->string('third_link')->nullable();
            $table->string('steam_link')->nullable();
            $table->text('result')->nullable();
            $table->datetime('result_at')->nullable();
            $table->dropColumn('id_steam');
            });
    }
};
