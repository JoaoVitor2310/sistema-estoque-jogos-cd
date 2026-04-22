<?php

namespace App\Domain\Bundles;

/**
 * Determina o tipo de um bundle pelo título.
 *
 * PHP puro — zero dependência do Laravel ou do banco de dados.
 *
 * Regra: títulos que contêm "Choice" (case-insensitive) são do tipo
 * 'choice' (Humble Choice); todos os demais são 'bundle'.
 */
final class BundleTypeResolver
{
    public const TYPE_CHOICE = 'choice';

    public const TYPE_BUNDLE = 'bundle';

    /**
     * Resolve o tipo do bundle a partir do título.
     *
     * @param  string  $title  Título do bundle vindo da API
     * @return string 'choice' ou 'bundle'
     */
    public static function resolve(string $title): string
    {
        return stripos($title, 'Choice') !== false
            ? self::TYPE_CHOICE
            : self::TYPE_BUNDLE;
    }
}
