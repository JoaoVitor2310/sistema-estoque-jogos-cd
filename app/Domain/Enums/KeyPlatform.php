<?php

namespace App\Domain\Enums;

/**
 * Plataforma de distribuição da key (onde ela é resgatada).
 *
 * Detectada automaticamente via regex no formato da chave —
 * lógica que será extraída para Domain/Platform/PlatformIdentifier na Fase 2.
 */
enum KeyPlatform: string
{
    case Steam = 'Steam';
    case EA = 'EA';
    case Epic = 'Epic Games Store';
    case GOG = 'GOG';
    case Xbox = 'Xbox';
    case PSN = 'PlayStation Network';
    case Unknown = 'Desconhecido';

    /**
     * Tenta identificar a plataforma pelo formato da chave.
     *
     * Regras de detecção (ordem importa — mais específico primeiro):
     *  - Steam:  XXXXX-XXXXX-XXXXX  (grupos de 5 alfanuméricos separados por hífen)
     *  - EA:     código EA/Origin (formato variável)
     *  - Epic:   começa com EGS_ ou EPICGAMES_
     *  - GOG:    XXXXX-XXXXX-XXXXX-XXXXX (4 grupos)
     *  - Xbox:   XXXXX-XXXXX-XXXXX-XXXXX-XXXXX (5 grupos)
     *  - PSN:    XXXXX-XXXXX-XXXXX (com letras maiúsculas)
     *
     * Nota: a implementação completa virá em Domain/Platform/PlatformIdentifier (Fase 2).
     * Este método é um placeholder para o Enum funcionar de forma standalone.
     */
    public static function fromKeyFormat(string $key): self
    {
        $key = trim($key);

        // Xbox: 5 grupos de 5 alfanuméricos (XXXXX-XXXXX-XXXXX-XXXXX-XXXXX)
        if (preg_match('/^[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}$/', $key)) {
            return self::Xbox;
        }

        // Steam: 3 grupos de 5 alfanuméricos (XXXXX-XXXXX-XXXXX)
        if (preg_match('/^[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}$/', $key)) {
            return self::Steam;
        }

        // GOG: 4 grupos de 5 alfanuméricos (XXXXX-XXXXX-XXXXX-XXXXX)
        if (preg_match('/^[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}$/', $key)) {
            return self::GOG;
        }

        // Epic Games Store
        if (preg_match('/^(EGS_|EPICGAMES_)/i', $key)) {
            return self::Epic;
        }

        // EA / Origin: formato numérico longo ou XXXX-XXXX-XXXX-XXXX-XXXX
        if (preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $key)) {
            return self::EA;
        }

        // PSN: XXXXX-XXXXX-XXXXX com mistura de letras e números
        if (preg_match('/^[A-Z]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}$/', $key)) {
            return self::PSN;
        }

        return self::Unknown;
    }

    /**
     * Indica se a plataforma é Steam (usada em integrações com Steamcharts, etc.).
     */
    public function isSteam(): bool
    {
        return $this === self::Steam;
    }
}
