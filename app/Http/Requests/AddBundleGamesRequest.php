<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Jogos a vincular a um bundle (POST /bundles/{bundle}/games).
 */
class AddBundleGamesRequest extends FormRequest
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
            'games' => ['required', 'array', 'min:1'],
            'games.*' => ['required', 'integer', 'exists:games,id'],
        ];
    }

    /**
     * @return int[]
     */
    public function gameIds(): array
    {
        return array_map('intval', $this->validated()['games']);
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
