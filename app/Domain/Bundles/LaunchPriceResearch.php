<?php

namespace App\Domain\Bundles;

use Carbon\CarbonInterface;

/**
 * Quando pesquisar o preço de lançamento dos jogos de um bundle.
 *
 * O preço de lançamento só vale perto do lançamento: é a referência de quanto
 * os jogos valiam quando o bundle saiu. Uma pesquisa que falha (serviço fora,
 * timeout, segredo errado) é tentada de novo nas rodadas seguintes da sync,
 * mas só dentro da janela — passado dela, o preço de hoje já não é o de
 * lançamento, e gravá-lo distorceria o lucro estimado do bundle.
 */
final class LaunchPriceResearch
{
    /** Dias após o lançamento do bundle em que ainda vale pesquisar. */
    public const WINDOW_DAYS = 3;

    public static function isDue(bool $hasLaunchPrice, CarbonInterface $releaseDate, CarbonInterface $now): bool
    {
        if ($hasLaunchPrice) {
            return false;
        }

        return $now->lessThanOrEqualTo($releaseDate->copy()->addDays(self::WINDOW_DAYS)->endOfDay());
    }
}
