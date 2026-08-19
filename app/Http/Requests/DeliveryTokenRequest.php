<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * O token digitado pelo supplier na página da entrega.
 *
 * O limite generoso de tamanho é proposital: quem valida a forma do token é
 * [[App\Domain\Trades\DeliveryCredential]], ao normalizar. Recusar aqui por
 * tamanho ou por caractere devolveria uma mensagem diferente para "token com
 * grafia estranha" e para "token errado" — e essa diferença é exatamente o que
 * um atacante usaria para descobrir o formato.
 */
class DeliveryTokenRequest extends FormRequest
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
            'token' => ['required', 'string', 'max:64'],
        ];
    }
}
