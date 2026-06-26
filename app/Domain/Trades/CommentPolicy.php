<?php

namespace App\Domain\Trades;

use Carbon\Carbon;

class CommentPolicy
{
    public const INTERVAL_DAYS = 14;

    /**
     * @param  array<int, array<string, mixed>>  $profitable
     */
    public static function shouldComment(
        array $profitable,
        bool $gamesChanged,
        ?Carbon $lastCommentedAt,
        ?Carbon $now = null,
    ): bool {
        if (empty($profitable)) {
            return false;
        }

        $now ??= Carbon::now();

        return $gamesChanged
            || $lastCommentedAt === null
            || $lastCommentedAt->diffInDays($now) >= self::INTERVAL_DAYS;
    }
}
