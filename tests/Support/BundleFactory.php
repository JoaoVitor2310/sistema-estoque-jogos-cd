<?php

namespace Tests\Support;

use App\Domain\Games\GameNameNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Seed do trio que o lookup de bundle atravessa: `games`, `bundles` e a
 * tabela de junção `bundle_games`.
 *
 * Namespaced pelo mesmo motivo de [[TradeFactory]]: helper solto no topo de um
 * arquivo de teste é promovido ao namespace global pelo Pest, e este é usado
 * por dois arquivos — os dois caminhos que resolvem bundle ao criar trade.
 *
 * Insere por query builder porque o teste precisa fixar `release_date` no
 * passado, que é justamente o eixo da janela de `BundleGameLookup::RECENT_MONTHS`.
 */
final class BundleFactory
{
    public static function withGame(string $gameName, string $bundleName, string $releaseDate): void
    {
        $now = now()->toDateTimeString();

        $gameId = DB::table('games')->insertGetId([
            'name' => $gameName,
            'normalized_name' => GameNameNormalizer::normalize($gameName),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $bundleId = DB::table('bundles')->insertGetId([
            'name' => $bundleName,
            'release_date' => $releaseDate,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('bundle_games')->insert([
            'bundle_id' => $bundleId,
            'game_id' => $gameId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
