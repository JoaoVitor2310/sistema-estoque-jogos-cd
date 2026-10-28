<?php

use App\Domain\Games\GameNameNormalizer;
use App\Domain\Games\GiveawayTitle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reextrai o jogo e a origem de todo giveaway já gravado, com o parser atual.
 *
 * O parser não conhecia a origem sem parêntese ("HELLCARD Steam Key Giveaway")
 * e gravou "HELLCARD Steam" como nome. A sincronização só reprocessa o que
 * ainda está no feed, então um giveaway encerrado ficaria com o nome errado
 * para sempre — e é pelo nome normalizado que a linha de trade é marcada.
 * O vínculo com `games` se refaz sozinho na próxima passada da sincronização,
 * para todo giveaway ainda na janela.
 */
return new class extends Migration
{
    public function up(): void
    {
        $giveaways = DB::table('giveaways')->get(['id', 'title', 'game_name', 'normalized_game_name', 'store']);

        foreach ($giveaways as $giveaway) {
            $gameName = GiveawayTitle::gameName($giveaway->title);

            $parsed = [
                'game_name' => $gameName,
                'normalized_game_name' => $gameName === null ? null : GameNameNormalizer::normalize($gameName),
                'store' => GiveawayTitle::store($giveaway->title),
            ];

            if ($parsed === ['game_name' => $giveaway->game_name, 'normalized_game_name' => $giveaway->normalized_game_name, 'store' => $giveaway->store]) {
                continue;
            }

            DB::table('giveaways')->where('id', $giveaway->id)->update($parsed + ['updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Sem volta: o nome antigo era um erro de parsing e não vale restaurar.
    }
};
