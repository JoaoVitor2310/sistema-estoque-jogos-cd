<?php

namespace App\Http\Requests;

use App\UseCases\Financial\BootstrapFinancialMonthData;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Estado de abertura do primeiro fechamento (bootstrap): porcentagens de prefill
 * e saldos iniciais das quatro contas. Só o primeiro mês passa por aqui — os
 * seguintes nascem do fechamento anterior.
 */
class StoreFinancialMonthRequest extends FormRequest
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
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'reinvestment_percent' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'emergency_percent' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'partner_one_share' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'opening_balances' => ['nullable', 'array'],
            'opening_balances.principal' => ['nullable', 'numeric', 'min:0'],
            'opening_balances.tf2' => ['nullable', 'numeric', 'min:0'],
            'opening_balances.reinvestment' => ['nullable', 'numeric', 'min:0'],
            'opening_balances.emergency' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * Hidrata o input tipado do UseCase a partir do payload já validado —
     * o mapeamento mora na fronteira, não no controller.
     */
    public function toData(): BootstrapFinancialMonthData
    {
        $data = $this->validated();

        return new BootstrapFinancialMonthData(
            year: isset($data['year']) ? (int) $data['year'] : null,
            month: isset($data['month']) ? (int) $data['month'] : null,
            reinvestmentPercent: isset($data['reinvestment_percent']) ? (float) $data['reinvestment_percent'] : null,
            emergencyPercent: isset($data['emergency_percent']) ? (float) $data['emergency_percent'] : null,
            partnerOneShare: isset($data['partner_one_share']) ? (float) $data['partner_one_share'] : null,
            openingBalances: array_map(fn ($value): float => (float) $value, $data['opening_balances'] ?? []),
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
