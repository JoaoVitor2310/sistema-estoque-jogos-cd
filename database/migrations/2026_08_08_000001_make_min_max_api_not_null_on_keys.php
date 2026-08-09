<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keys', function (Blueprint $table) {
            $table->decimal('min_api', 8, 2)->nullable(false)->change();
            $table->decimal('max_api', 8, 2)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('keys', function (Blueprint $table) {
            $table->decimal('min_api', 8, 2)->nullable()->change();
            $table->decimal('max_api', 8, 2)->nullable()->change();
        });
    }
};
