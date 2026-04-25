<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keys', function (Blueprint $table) {
            $table->dropColumn(['email', 'in_bundle']);
        });
    }

    public function down(): void
    {
        Schema::table('keys', function (Blueprint $table) {
            $table->string('email')->nullable();
            $table->boolean('in_bundle')->nullable();
        });
    }
};
