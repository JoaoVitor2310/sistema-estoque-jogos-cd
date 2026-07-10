<?php

namespace App\Console\Commands;

use App\Domain\Games\GameNameNormalizer;
use App\Models\Game;
use Illuminate\Console\Command;

class BackfillGamesNormalizedNameCommand extends Command
{
    protected $signature = 'games:backfill-normalized-name';

    protected $description = 'Preenche games.normalized_name para registros existentes a partir de games.name';

    public function handle(): int
    {
        $total = 0;

        Game::whereNotNull('name')->chunkById(500, function ($games) use (&$total) {
            foreach ($games as $game) {
                $game->normalized_name = GameNameNormalizer::normalize($game->name);
                $game->saveQuietly();
                $total++;
            }
        });

        $this->info("normalized_name preenchido em {$total} jogo(s).");

        return self::SUCCESS;
    }
}
