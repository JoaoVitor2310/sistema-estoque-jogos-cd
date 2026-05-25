<?php

namespace App\Http\Requests;

use App\Domain\Enums\ClaimType;
use App\Domain\Enums\KeyFormat;
use App\Domain\Enums\SellPlatform;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreGameRequestArray extends FormRequest
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
            'games' => 'required|array',
            'games.*.color' => 'nullable',
            'games.*.claim_type' => ['nullable', Rule::enum(ClaimType::class)],
            'games.*.steam_id' => ['string', 'nullable'],
            'games.*.gamivo_id' => ['string', 'nullable'],
            'games.*.key_format' => ['nullable', Rule::enum(KeyFormat::class)],
            'games.*.key_code' => 'required',
            'games.*.game_name' => 'required',
            'games.*.region' => ['string', 'nullable'],
            'games.*.notes' => ['string', 'nullable'],
            'games.*.sell_platform' => ['nullable', Rule::enum(SellPlatform::class)],
            'games.*.market_price' => ['required', 'decimal:0,2', 'gt:0'],
            'games.*.min_api' => ['nullable', 'decimal:0,2'],
            'games.*.max_api' => ['nullable', 'decimal:0,2'],
            'games.*.total_paid' => ['string', 'nullable'],
            'games.*.sold_price' => ['nullable', 'decimal:0,2'],
            'games.*.acquired_at' => ['required', 'string'],
            'games.*.listed_at' => ['nullable', 'string'],
            'games.*.sold_at' => ['nullable', 'string'],
            'games.*.expires_at' => ['nullable', 'string'],
            'games.*.supplier_url' => ['required', 'string'],
            'games.*.tf2_quantity' => ['required', 'decimal:0,2', 'gt:0'],
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
