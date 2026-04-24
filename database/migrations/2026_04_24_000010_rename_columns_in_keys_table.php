<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keys', function (Blueprint $table) {
            $table->dropColumn('precoJogo');
            $table->renameColumn('observacao', 'notes');
            $table->renameColumn('steamId', 'steam_id');
            $table->renameColumn('minApiGamivo', 'min_api');
            $table->renameColumn('maxApiGamivo', 'max_api');
        });
    }

    public function down(): void
    {
        Schema::table('keys', function (Blueprint $table) {
            $table->decimal('precoJogo', 8, 2)->nullable();
            $table->renameColumn('notes', 'observacao');
            $table->renameColumn('steam_id', 'steamId');
            $table->renameColumn('min_api', 'minApiGamivo');
            $table->renameColumn('max_api', 'maxApiGamivo');
        });
    }
};
