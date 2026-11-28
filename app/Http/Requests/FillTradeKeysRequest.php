<?php

namespace App\Http\Requests;

use App\UseCases\Trades\DTO\ChatKeysDTO;
use Illuminate\Foundation\Http\FormRequest;

/**
 * As keys que o supplier colou no chat, em pares (linha da trade, key).
 *
 * Só `key_code`: região, bundle e validade não vêm pelo chat. Quem recusa
 * coluna fora do alcance do supplier continua sendo
 * [[App\Domain\Enums\TradeLineAuthority]], no UseCase — esta lista não é a
 * barreira. Ver docs/adr/0017.
 */
class FillTradeKeysRequest extends FormRequest
{
    /**
     * O bloco do modelo de keys no chatbot: no máximo 75 jogos por mensagem da
     * Steam (`MAX_OFFER_LINES` do chatbot-steam). Uma oferta maior chega em
     * vários pedidos, um por bloco colado; um pedido maior que um bloco não veio
     * de uma mensagem do supplier.
     */
    public const MAX_KEYS = 75;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'keys' => ['required', 'array', 'min:1', 'max:'.self::MAX_KEYS],
            'keys.*' => ['array:line_id,key_code'],
            'keys.*.line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'keys.*.key_code' => ['required', 'string', 'max:255'],
        ];
    }

    public function toDTO(): ChatKeysDTO
    {
        $keyCodes = [];

        foreach ($this->validated('keys') as $pair) {
            $keyCodes[(int) $pair['line_id']] = trim($pair['key_code']);
        }

        return new ChatKeysDTO($keyCodes);
    }
}
