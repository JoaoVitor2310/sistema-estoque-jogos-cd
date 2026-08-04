<?php

namespace App\Http\Requests;

use App\Domain\Enums\AccountType;
use App\UseCases\Financial\DTO\DistributeToPartnersDTO;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Saque dos dois sócios num único lançamento.
 *
 * `partner_one_share` é obrigatório: a tela o pré-preenche com a porcentagem do
 * mês, mas o sistema nunca distribui sozinho (ver docs/adr/0005) — quem lança
 * confirma a divisão. O Sócio 2 leva o resto exato, então não há campo para ele.
 */
class DistributeToPartnersRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'gt:0'],
            'partner_one_share' => ['required', 'numeric', 'min:0', 'max:1'],
            // Espelha o invariante de domínio: sacar de uma caixinha exige justificativa.
            'description' => [Rule::requiredIf(fn (): bool => $this->debitsReserveFund()), 'nullable', 'string'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }

    public function toDTO(): DistributeToPartnersDTO
    {
        $data = $this->validated();

        return new DistributeToPartnersDTO(
            source: AccountType::from($data['source']),
            amount: (float) $data['amount'],
            partnerOneShare: (float) $data['partner_one_share'],
            description: $data['description'] ?? null,
            occurredAt: $data['occurred_at'] ?? null,
        );
    }

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
