<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class GameRequestArray extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'games' => 'required|array', // Garante que 'games' é um array
            'games.*.name' => 'required',
            'games.*.region' => ['string', 'nullable'],
            'games.*.gamivo_id' => ['string', 'nullable'],
            'games.*.steamcharts_id' => ['string', 'nullable'],
            'games.*.popularity' => ['integer', 'nullable'],
            'games.*.price_tf2' => ['decimal:0,2', 'nullable'],
            'games.*.price_euro' => ['decimal:0,2', 'nullable'],
            'games.*.release_date' => ['string', 'nullable'],
        ];
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
