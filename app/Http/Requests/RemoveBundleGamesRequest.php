<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Jogos a desvincular de um bundle (DELETE /bundles/{bundle}/games).
 *
 * A rota não tinha FormRequest, e `games` ausente virava `detach(null)` — que
 * no Eloquent desvincula **todos** os jogos do bundle, não nenhum.
 */
class RemoveBundleGamesRequest extends FormRequest
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
