<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('trades')->truncate();

        Schema::table('trades', function (Blueprint $table) {
            $table->renameColumn('rows', 'games');
            $table->date('date')->nullable()->after('title');
            $table->decimal('tf2_qty', 8, 2)->nullable()->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->renameColumn('games', 'rows');
            $table->dropColumn(['date', 'tf2_qty']);
        });
    }
};
