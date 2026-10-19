<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('giveaways', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('gamerpower_id')->unique();
            $table->string('title');
            $table->string('game_name')->nullable();
            $table->string('normalized_game_name')->nullable()->index();
            $table->string('store')->nullable();
            $table->decimal('worth', 10, 2)->nullable();
            $table->string('gamerpower_url');
            $table->timestamp('published_date');
            $table->timestamp('end_date')->nullable();
            $table->string('status');
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('giveaways');
    }
};
