<?php

namespace Tests\Support;

use App\Domain\Enums\GiveawayStatus;
use App\Domain\Games\GameNameNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seed de `giveaways` para os testes da marca na trade.
 *
 * Namespaced pelo mesmo motivo de [[TradeFactory]]: é usado por vários
 * arquivos — todo caminho que grava linha de trade avalia o giveaway.
 *
 * Insere por query builder porque o teste precisa fixar `published_date` e
 * `last_seen_at` no passado, que são o eixo da janela de
 * [[App\Domain\Games\GiveawayPolicy]].
 */
final class GiveawayFactory
{
    private static int $nextId = 1;

    /** Giveaway ainda no feed: o jogo está em giveaway, sem prazo. */
    public static function active(string $gameName): int
    {
        return self::insert($gameName, GiveawayStatus::Active, now()->subDays(3), now());
    }

    /**
     * Giveaway que durou `$durationDays` dias e saiu do feed há `$endedDaysAgo` dias.
     */
    public static function ended(string $gameName, int $durationDays, int $endedDaysAgo): int
    {
        $lastSeen = now()->subDays($endedDaysAgo);

        return self::insert($gameName, GiveawayStatus::Ended, $lastSeen->copy()->subDays($durationDays), $lastSeen);
    }

    /** @return int o `id` do giveaway gravado */
    private static function insert(string $gameName, GiveawayStatus $status, Carbon $publishedDate, Carbon $lastSeenAt): int
    {
        $id = self::$nextId++;

        return DB::table('giveaways')->insertGetId([
            'gamerpower_id' => $id,
            'title' => $gameName.' (Steam) Key Giveaway',
            'game_name' => $gameName,
            'normalized_game_name' => GameNameNormalizer::normalize($gameName),
            'store' => 'Steam',
            'gamerpower_url' => 'https://www.gamerpower.com/giveaway-'.$id,
            'published_date' => $publishedDate,
            'status' => $status->value,
            'last_seen_at' => $lastSeenAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
