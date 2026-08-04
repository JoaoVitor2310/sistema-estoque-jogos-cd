<?php

namespace App\Http\Requests;

use App\UseCases\Financial\DTO\RecordTf2AllocationDTO;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Verba de TF2 do mês: quantidade × preço unitário.
 *
 * Não há conta a escolher — a alocação é sempre Principal → Tf2 —, por isso
 * nenhum enum entra aqui. O valor é derivado, nunca informado: quem manda é a
 * meta (ver docs/adr/0005).
 */
class RecordTf2AllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }

    public function toDTO(): RecordTf2AllocationDTO
    {
        $data = $this->validated();

        return new RecordTf2AllocationDTO(
            quantity: (float) $data['quantity'],
            unitPrice: (float) $data['unit_price'],
            description: $data['description'] ?? null,
            occurredAt: $data['occurred_at'] ?? null,
        );
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'statusCode' => 422,
            'message' => 'Dados inválidos',
            'errors' => $validator->errors(),
            'data' => [],
        ], 422));
    }
}
