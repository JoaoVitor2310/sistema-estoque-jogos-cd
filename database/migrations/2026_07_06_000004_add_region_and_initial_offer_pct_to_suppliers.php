<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('region')->nullable()->after('name');
            $table->unsignedSmallInteger('initial_offer_pct')->nullable()->default(100)->after('region');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['region', 'initial_offer_pct']);
        });
    }
};
