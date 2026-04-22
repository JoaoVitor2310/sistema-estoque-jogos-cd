<?php

namespace App\Domain\Enums;

/**
 * Representa os marketplaces suportados pelo sistema.
 *
 * Os valores inteiros correspondem aos IDs na tabela `tipo_formato` / `plataforma`
 * usados historicamente no banco. Mantidos aqui para desacoplar os números mágicos
 * espalhados no código e centralizar numa única fonte da verdade.
 *
 * G2A, Kinguin e Troca serão REMOVIDOS numa fase futura — mantidos
 * temporariamente para não quebrar código legado durante a migração.
 */
enum Marketplace: int
{
    /** @deprecated Será removido na refatoração */
    case G2A = 2;

    case Gamivo = 3;

    /** @deprecated Será removido na refatoração */
    case Kinguin = 4;

    /** @deprecated Será removido na refatoração */
    case Troca = 7;

    /**
     * Retorna o nome legível do marketplace.
     */
    public function label(): string
    {
        return match ($this) {
            self::G2A => 'G2A',
            self::Gamivo => 'Gamivo',
            self::Kinguin => 'Kinguin',
            self::Troca => 'Troca',
        };
    }

    /**
     * Indica se o marketplace usa o preço do cliente diretamente como preço de venda
     * (sem cálculo de faixa de taxa G2A).
     */
    public function useClientPriceAsSalePrice(): bool
    {
        return match ($this) {
            self::Gamivo, self::Kinguin, self::Troca => true,
            self::G2A => false,
        };
    }
}
