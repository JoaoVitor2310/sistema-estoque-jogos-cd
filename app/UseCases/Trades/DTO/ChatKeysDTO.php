<?php

namespace App\UseCases\Trades\DTO;

/**
 * As keys que o supplier colou no chat, já validadas: uma por linha da trade.
 *
 * Só a key. Região, bundle e validade não vêm pelo chat e ficam com a equipe
 * na conferência (docs/adr/0017).
 */
final class ChatKeysDTO
{
    /**
     * @param  array<int, string>  $keyCodes  `key_code` por id da linha da trade
     */
    public function __construct(
        public readonly array $keyCodes,
    ) {}
}
