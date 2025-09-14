<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreBundleRequest extends FormRequest
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
            "name" => "required|string|max:255",
            "type" => "required|string|in:bundle,choice",
            "description" => "nullable|string|max:500",
            "price_tf2" => "nullable|decimal:0,2|min:0",
            "price_euro" => "nullable|decimal:0,2|min:0",
            "release_date" => "required|date",
            "games" => "nullable|array",
            "games.*" => "nullable|integer|exists:games,id",
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'statusCode' => 422,
            'message' => 'Dados inválidos',
            'errors' => $validator->errors(),
            'data' => []
        ], 422));
    }

    public function messages(): array
    {
        return [
            'release_date.required' => 'A data de lançamento é obrigatória.',
            'release_date.date' => 'A data de lançamento deve ser uma data válida.',
        ];
    }
}
