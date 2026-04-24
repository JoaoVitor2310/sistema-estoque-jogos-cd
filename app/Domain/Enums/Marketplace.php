<?php

namespace App\Domain\Enums;

/**
 * Representa os marketplaces suportados pelo sistema.
 *
 * O valor inteiro corresponde ao ID histórico na tabela `tipo_formato` / `plataforma`.
 * Mantido aqui para desacoplar números mágicos e centralizar numa única fonte da verdade.
 */
enum Marketplace: int
{
    case Gamivo = 3;

    /**
     * Retorna o nome legível do marketplace.
     */
    public function label(): string
    {
        return match ($this) {
            self::Gamivo => 'Gamivo',
        };
    }

    /**
     * Indica se o marketplace usa o preço do cliente diretamente como preço de venda.
     */
    public function useClientPriceAsSalePrice(): bool
    {
        return match ($this) {
            self::Gamivo => true,
        };
    }
}
