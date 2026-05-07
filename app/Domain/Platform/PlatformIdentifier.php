<?php

namespace App\Domain\Platform;

/**
 * Identifica a plataforma de uma key pelo formato do código.
 *
 * A ordem dos padrões importa: padrões mais específicos devem vir antes
 * de padrões que possam sobrepor (ex: EA antes de EA/Ubisoft).
 */
final class PlatformIdentifier
{
    /**
     * Mapa de plataforma → regex, em ordem de prioridade.
     * Todos os padrões usam \w (word characters: a-z, A-Z, 0-9, _).
     */
    private const PATTERNS = [
        'Steam' => '/^\w{5}-\w{5}-\w{5}$|^\w{15}\s\w{2}$/',
        'EA' => '/^\w{4}-\w{4}-\w{4}-\w{4}-\w{4}$/',
        'EA/Ubisoft' => '/^\w{4}-\w{4}-\w{4}-\w{4}$/',
        'EGS' => '/^\w{5}-\w{5}-\w{5}-\w{5}$/',
        'GOG' => '/^\w{18}$/',
        'XBOX' => '/^\w{5}-\w{5}-\w{5}-\w{5}-\w{5}$/',
        'PSN' => '/^\w{4}-\w{4}-\w{4}$/',
    ];

    private const UNKNOWN = 'DESCONHECIDO';

    /**
     * Identifica a plataforma pelo formato do código da key.
     *
     * Retorna 'DESCONHECIDO' quando nenhum padrão é encontrado.
     *
     * @param  string  $keyCode  Código da key (ex: "XXXXX-XXXXX-XXXXX")
     * @return string Nome da plataforma ou 'DESCONHECIDO'
     */
    public static function identify(string $keyCode): string
    {
        foreach (self::PATTERNS as $platform => $pattern) {
            if (preg_match($pattern, $keyCode)) {
                return $platform;
            }
        }

        return self::UNKNOWN;
    }
}
