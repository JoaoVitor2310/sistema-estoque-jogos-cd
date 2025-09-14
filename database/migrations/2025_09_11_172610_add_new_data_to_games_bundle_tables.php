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
        Schema::table('games', function (Blueprint $table) {
            $table->string('region')->after('name')->nullable()->comment('Região do jogo');
            $table->string('id_steamcharts')->after('id_gamivo')->nullable()->comment('ID do SteamCharts para automatizar popularidade');
        });

        Schema::table('bundles', function (Blueprint $table) {
            $table->enum('type', ['bundle', 'choice'])->after('name')->nullable()->comment('Tipo do bundle');
        });
    }
    
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('region');
            $table->dropColumn('id_steamcharts');
        });

        Schema::table('bundles', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
