<?php

namespace App\Http\Requests;

use App\UseCases\Trades\DTO\DeliveryTradeDTO;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Os campos da própria trade que o supplier preenche: o total de TF2 acertado e
 * a observação livre.
 *
 * `tf2_qty` é numérico estrito — ao contrário dos campos de linha, ele não é
 * salvo a cada tecla, e é o número que alimenta o rateio de `individual_cost`
 * do lote no import.
 */
class DeliveryTradeRequest extends FormRequest
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
            'tf2_qty' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'supplier_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function toDTO(): DeliveryTradeDTO
    {
        return DeliveryTradeDTO::fromValidated($this->validated());
    }
}
