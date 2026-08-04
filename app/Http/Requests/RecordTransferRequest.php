<?php

namespace App\Http\Requests;

use App\Domain\Enums\AccountType;
use App\UseCases\Financial\DTO\RecordTransferDTO;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Transferência entre duas contas do fechamento em aberto.
 *
 * O valor vem de uma de duas formas, nunca das duas: `amount` (valor fechado) ou
 * `fraction` (porcentagem do saldo atual da origem, ex: 0.20). `required_without`
 * cobre o caso de não vir nenhum; `prohibits` cobre o de virem os dois — assim o
 * erro cai no campo, em vez de virar exceção genérica do UseCase.
 */
class RecordTransferRequest extends FormRequest
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
            'source' => ['required', Rule::enum(AccountType::class)],
            // Débito e crédito na mesma conta se anulam; o domínio recusa e aqui
            // o erro cai no campo.
            'destination' => ['required', 'different:source', Rule::enum(AccountType::class)],
            'amount' => ['required_without:fraction', 'prohibits:fraction', 'nullable', 'numeric', 'gt:0'],
            'fraction' => ['required_without:amount', 'nullable', 'numeric', 'gt:0', 'max:1'],
            // Espelha o invariante de domínio: tirar de uma caixinha exige justificativa.
            'description' => [Rule::requiredIf(fn (): bool => $this->debitsReserveFund()), 'nullable', 'string'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }

    public function toDTO(): RecordTransferDTO
    {
        $data = $this->validated();

        return new RecordTransferDTO(
            source: AccountType::from($data['source']),
            destination: AccountType::from($data['destination']),
            amount: isset($data['amount']) ? (float) $data['amount'] : null,
            fraction: isset($data['fraction']) ? (float) $data['fraction'] : null,
            description: $data['description'] ?? null,
            occurredAt: $data['occurred_at'] ?? null,
        );
    }

    /**
     * A origem é uma caixinha — toda transferência a debita, qualquer que seja o
     * destino, então basta olhar a origem.
     */
    private function debitsReserveFund(): bool
    {
        $source = AccountType::tryFrom((string) $this->input('source'));

        return $source !== null && $source->isFund();
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
