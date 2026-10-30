<?php

namespace App\Domain\Suppliers;

/**
 * O SteamID64 de uma conta individual da Steam, que identifica o supplier.
 *
 * Toda conta individual soma o número da conta a 76561197960265728, então o
 * SteamID64 tem 17 dígitos e começa por 7656119 — o prefixo cobre bilhões de
 * contas além das que existem. Qualquer outro texto criaria um supplier sem
 * perfil de verdade, com uma URL que não abre.
 */
final class SteamId
{
    /** Para a regra `regex:` do FormRequest e para [[self::isValid]]. */
    public const PATTERN = '/^7656119\d{10}$/';

    public static function isValid(string $steamId): bool
    {
        return preg_match(self::PATTERN, $steamId) === 1;
    }
}
