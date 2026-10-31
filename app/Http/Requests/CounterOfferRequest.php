<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * O total que o supplier pediu, na moeda da trade — com centavos, que é como
 * ele lê a oferta.
 */
class CounterOfferRequest extends FormRequest
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
            'requested_total' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999.99'],
        ];
    }

    /** Em centavos, para a conta da contraproposta ser exata. */
    public function requestedCents(): int
    {
        return (int) round((float) $this->validated('requested_total') * 100);
    }
}
