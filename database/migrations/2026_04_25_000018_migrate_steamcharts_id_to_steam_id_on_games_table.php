<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill: copia steamcharts_id para steam_id onde steam_id ainda está vazio
        DB::statement('UPDATE games SET steam_id = steamcharts_id WHERE steamcharts_id IS NOT NULL AND steam_id IS NULL');

        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('steamcharts_id');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('steamcharts_id')->nullable()->after('gamivo_id');
        });

        DB::statement('UPDATE games SET steamcharts_id = steam_id WHERE steam_id IS NOT NULL');
    }
};
