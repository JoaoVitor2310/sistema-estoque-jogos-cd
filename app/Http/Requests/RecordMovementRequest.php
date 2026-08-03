<?php

namespace App\Http\Requests;

use App\Domain\Enums\AccountType;
use App\Domain\Enums\MovementCategory;
use App\UseCases\Financial\DTO\RecordMovementDTO;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Lançamento simples num fechamento em aberto:
 *   - income/expense exigem conta e valor;
 *   - tf2_purchase exige quantidade e preço (a conta é sempre a verba de TF2 e o
 *     valor é derivado de qtd × preço).
 *
 * Valida o payload e entrega um DTO de primitivos. Quem constrói o VO de domínio
 * é o UseCase — aqui só se decide se a requisição está bem formada.
 *
 * Transferência e distribuição têm rotas próprias, porque geram mais de uma linha.
 */
class RecordMovementRequest extends FormRequest
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
            'category' => ['required', Rule::enum(MovementCategory::class)->only([
                MovementCategory::Income,
                MovementCategory::Expense,
                MovementCategory::Tf2Purchase,
            ])],
            // A compra de TF2 sempre debita a verba do mês, então não leva conta nem valor.
            'account' => ['required_unless:category,tf2_purchase', 'nullable', Rule::enum(AccountType::class)],
            'amount' => ['required_unless:category,tf2_purchase', 'nullable', 'numeric', 'gt:0'],
            'quantity' => ['required_if:category,tf2_purchase', 'nullable', 'numeric', 'gt:0'],
            'unit_price' => ['required_if:category,tf2_purchase', 'nullable', 'numeric', 'gt:0'],
            // Espelha o invariante de domínio para que o erro caia no campo, e não
            // como exceção genérica: tirar de uma caixinha exige justificativa.
            'description' => [Rule::requiredIf(fn (): bool => $this->debitsReserveFund()), 'nullable', 'string'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }

    public function toDTO(): RecordMovementDTO
    {
        $data = $this->validated();

        return new RecordMovementDTO(
            category: MovementCategory::from($data['category']),
            account: isset($data['account']) ? AccountType::from($data['account']) : null,
            amount: isset($data['amount']) ? (float) $data['amount'] : null,
            quantity: isset($data['quantity']) ? (float) $data['quantity'] : null,
            unitPrice: isset($data['unit_price']) ? (float) $data['unit_price'] : null,
            description: $data['description'] ?? null,
            occurredAt: $data['occurred_at'] ?? null,
        );
    }

    /**
     * Uma saída de Reinvestimento ou Emergência — o único caso em que a
     * justificativa é obrigatória.
     */
    private function debitsReserveFund(): bool
    {
        if ($this->input('category') !== MovementCategory::Expense->value) {
            return false;
        }

        $account = AccountType::tryFrom((string) $this->input('account'));

        return $account !== null && $account->isFund();
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
