<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quais linhas de `games` cada giveaway atinge.
     *
     * Uma linha de `games` é o jogo numa região, e o giveaway derruba o preço
     * em todas: o vínculo liga o giveaway a cada uma delas pelo nome
     * normalizado, e é refeito a cada sincronização enquanto o giveaway estiver
     * na janela.
     */
    public function up(): void
    {
        Schema::create('giveaway_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('giveaway_id')->constrained('giveaways')->cascadeOnDelete();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['giveaway_id', 'game_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('giveaway_games');
    }
};
