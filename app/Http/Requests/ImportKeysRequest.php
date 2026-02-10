<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportKeysRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls',
                'max:10240', // 10MB
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'O arquivo é obrigatório.',
            'file.file' => 'Deve ser um arquivo válido.',
            'file.mimes' => 'O arquivo deve ser do tipo XLSX ou XLS.',
            'file.max' => 'O arquivo não pode ser maior que 10MB.',
        ];
    }
}
