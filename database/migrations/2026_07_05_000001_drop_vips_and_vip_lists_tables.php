<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('vip_lists');
        Schema::dropIfExists('vips');
    }

    public function down(): void
    {
        Schema::create('vips', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('id_steam')->nullable();
            $table->timestamps();
        });

        Schema::create('vip_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vip_id')->constrained('vips')->cascadeOnDelete();
            $table->string('status')->nullable();
            $table->text('result')->nullable();
            $table->timestamps();
        });
    }
};
