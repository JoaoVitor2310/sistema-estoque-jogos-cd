<?php

namespace App\Domain\Games;

/**
 * Extrai o nome do jogo e a origem do título de um giveaway do GamerPower.
 *
 * O GamerPower não manda o nome do jogo num campo próprio: ele vem dentro do
 * título, seguido da origem entre parênteses e do sufixo "Giveaway" —
 * "Dwarven Realms (Steam) Key Giveaway", "Leaper (IndieGala) Giveaway".
 *
 * Só o **final** do título é recortado: o último parêntese antes do sufixo é
 * a origem, e qualquer parêntese anterior faz parte do nome
 * ("Game (2013) (Steam) Giveaway" → "Game (2013)"). O recorte precisa vir
 * antes do [[GameNameNormalizer]], que tira o caractere `(` mas mantém o
 * conteúdo — sem ele "Leaper (IndieGala) Giveaway" viraria "leaper indiegala
 * giveaway" e nunca casaria com o jogo.
 */
final class GiveawayTitle
{
    private const PATTERN = '/^(?<name>[^(\s].*?)(?:\s*\((?<store>[^()]*)\))?\s*(?:Key\s+)?Giveaway$/i';

    /** `null` quando o título não segue o padrão — o giveaway fica sem jogo. */
    public static function gameName(string $title): ?string
    {
        $name = trim(self::match($title)['name'] ?? '');

        return $name === '' ? null : $name;
    }

    /** O que estava no parêntese final ("Steam", "IndieGala"); `null` se não houver. */
    public static function store(string $title): ?string
    {
        $store = trim(self::match($title)['store'] ?? '');

        return $store === '' ? null : $store;
    }

    /** @return array<int|string, string> */
    private static function match(string $title): array
    {
        return preg_match(self::PATTERN, trim($title), $matches) === 1 ? $matches : [];
    }
}
