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
        Schema::create('vip_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vip_id')->constrained('vips')->cascadeOnDelete();
            $table->enum('status', ['queued', 'completed', 'failed'])->default('queued');
            $table->text('result')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vip_lists');
    }
};
