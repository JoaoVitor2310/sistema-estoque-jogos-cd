<?php

namespace App\Http\Requests;

use App\UseCases\Trades\DTO\TradeLineDTO;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Campos de uma linha de trade, em snake_case igual às colunas.
 *
 * Um request só para criar e alterar: as regras dos campos são as mesmas, e a
 * escrita é sempre parcial — na criação todo campo é opcional (o caso comum é
 * "+ Linha", que nasce em branco), na alteração só o que veio é tocado.
 *
 * **Rigor desigual, e o critério é a digitação.** O autosave dispara a cada
 * tecla, então um campo só pode ser validado com rigor se todo prefixo de um
 * valor válido também for válido. `popularity` satisfaz isso (`5`, `50`, `500`)
 * e é validado como inteiro. `expires_at` não (`0`, `02`, `02/`, `02/0` a
 * caminho de `02/06/2027`), então chega como texto e é normalizado por
 * `TradeLineValue` — recusar meio caminho transformaria digitação em erro de
 * gravação. `market_price` é numérico estrito porque a tela só o envia no
 * evento `change`, já normalizado, e é o campo que alimenta o rateio de
 * `individual_cost` do lote.
 */
class TradeLineRequest extends FormRequest
{
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
            'game_name' => ['nullable', 'string', 'max:255'],
            'market_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'popularity' => ['nullable', 'integer', 'min:0'],
            'region' => ['nullable', 'string', 'max:255'],
            'bundle' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'string', 'max:10'],
            'key_code' => ['nullable', 'string', 'max:255'],
            'gamivo_id' => ['nullable', 'string', 'max:255'],

            // `position` decide onde a linha entra, e só existe na criação —
            // reordenar não é edição de campo. Alterando uma linha existente
            // ela é recusada, não ignorada: ignorar em silêncio faria uma
            // tentativa de reordenar parecer bem-sucedida.
            'position' => [$this->isUpdatingLine() ? 'prohibited' : 'nullable', 'integer', 'min:0'],
        ];
    }

    public function toDTO(): TradeLineDTO
    {
        return TradeLineDTO::fromValidated($this->validated());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'position.prohibited' => 'A posição de uma linha só pode ser definida ao criá-la.',
        ];
    }

    /**
     * A rota de alteração é a única que endereça uma linha existente — a de
     * criação recebe só a trade.
     */
    private function isUpdatingLine(): bool
    {
        return $this->route('line') !== null;
    }
}
