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
        Schema::table('bundle_games', function (Blueprint $table) {
            $table->decimal('bundle_launch_price', 10, 2)->after('game_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bundle_games', function (Blueprint $table) {
            $table->dropColumn('bundle_launch_price');
        });
    }
};
