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
 *
 * A origem também aparece **sem parêntese**, mas só como "Steam":
 * "HELLCARD Steam Key Giveaway" → "HELLCARD". Só [[BARE_STORE]] é reconhecida
 * assim, porque o feed é filtrado por Steam; aceitar qualquer palavra solta
 * cortaria o fim de nomes legítimos. O custo conhecido é um jogo cujo nome
 * termine em "Steam" perder essa palavra.
 */
final class GiveawayTitle
{
    /** Única origem que o GamerPower escreve sem parêntese no título. */
    public const BARE_STORE = 'Steam';

    private const PATTERN = '/^(?<name>[^(\s].*?)(?:\s*\((?<store>[^()]*)\)|\s+(?<bare_store>'.self::BARE_STORE.'))?\s*(?:Key\s+)?Giveaway$/i';

    /** `null` quando o título não segue o padrão — o giveaway fica sem jogo. */
    public static function gameName(string $title): ?string
    {
        $name = trim(self::match($title)['name'] ?? '');

        return $name === '' ? null : $name;
    }

    /** O que estava no parêntese final ("Steam", "IndieGala"), ou "Steam" solto; `null` se não houver. */
    public static function store(string $title): ?string
    {
        $match = self::match($title);

        if (($match['bare_store'] ?? '') !== '') {
            return self::BARE_STORE;
        }

        $store = trim($match['store'] ?? '');

        return $store === '' ? null : $store;
    }

    /** @return array<int|string, string> */
    private static function match(string $title): array
    {
        return preg_match(self::PATTERN, trim($title), $matches) === 1 ? $matches : [];
    }
}
