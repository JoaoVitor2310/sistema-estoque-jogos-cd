<?php

namespace App\Http\Requests;

use App\Domain\Enums\SupplierCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareForValidation(): void
    {
        $steamId = $this->input('steam_id');

        if ($steamId) {
            $this->merge(['url' => 'https://steamcommunity.com/profiles/'.$steamId]);
        }
    }

    public function rules(): array
    {
        $ignore = $this->route('supplier');

        return [
            'name' => ['nullable', 'string', 'max:255', Rule::unique('suppliers', 'name')->ignore($ignore)],
            'steam_id' => ['nullable', 'string', 'max:255', Rule::unique('suppliers', 'steam_id')->ignore($ignore)],
            'url' => ['nullable', 'string', 'max:255', Rule::unique('suppliers', 'url')->ignore($ignore)],
            'category' => ['nullable', Rule::enum(SupplierCategory::class)],
            'is_added' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Este nome já está cadastrado.',
            'steam_id.unique' => 'Este ID Steam já está cadastrado.',
            'url.unique' => 'Esta URL já está cadastrada.',
        ];
    }
}
