<?php

namespace App\Http\Requests;

use App\Domain\Enums\ClaimType;
use App\Domain\Enums\KeyFormat;
use App\Domain\Enums\SellPlatform;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreGameRequest extends FormRequest
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
            'color' => 'nullable',
            'claim_type' => ['nullable', Rule::enum(ClaimType::class)],
            'steam_id' => ['string', 'nullable'],
            'gamivo_id' => ['string', 'nullable'],
            'key_format' => ['nullable', Rule::enum(KeyFormat::class)],
            'key_code' => 'required',
            'game_name' => 'required',
            'region' => ['string', 'nullable'],
            'notes' => ['string', 'nullable'],
            'sell_platform' => ['nullable', Rule::enum(SellPlatform::class)],
            'market_price' => ['required', 'decimal:0,2', 'gt:0'],
            'min_api' => ['nullable', 'decimal:0,2'],
            'max_api' => ['nullable', 'decimal:0,2'],
            'total_paid' => ['string', 'nullable'],
            'sold_price' => ['nullable', 'decimal:0,2', 'gt:0'],
            'acquired_at' => ['required', 'string'],
            'listed_at' => ['nullable', 'string'],
            'sold_at' => ['nullable', 'string'],
            'expires_at' => ['nullable', 'string'],
            'supplier_url' => ['required', 'string'],
            'tf2_quantity' => ['nullable', 'decimal:0,2', 'gt:0'],
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
