<?php

namespace App\Http\Requests;

use App\Services\Games\GameRepository;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Whitelist de filtros de POST /games/search.
 *
 * A rota exige `can-edit`, então aqui não há o risco de vazamento que motivou o
 * IndexKeysRequest — quem chega enxerga a tabela inteira. O que se resolve é o
 * outro lado do mesmo problema: o filtro saía de `$request->except('page')`,
 * então qualquer chave do payload virava `where` sobre uma coluna homônima, e
 * um nome que não fosse coluna derrubava a busca com 500.
 */
class IndexGamesRequest extends FormRequest
{
    /**
     * Todo nome de campo aceito pelo endpoint.
     *
     * @return string[]
     */
    public static function allowedKeys(): array
    {
        return array_merge(GameRepository::allowedFilters(), ['limit', 'page']);
    }

    /**
     * Descarta os campos vazios antes de validar.
     *
     * Games.vue e Bundles.vue enviam o objeto de busca inteiro a cada consulta,
     * inclusive os campos que o usuário não preencheu.
     */
    protected function prepareForValidation(): void
    {
        $filled = array_filter(
            $this->all(),
            fn ($value) => $value !== null && $value !== '' && $value !== [],
        );

        $this->replace($filled);
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.GameRepository::MAX_LIMIT],
        ];

        foreach (GameRepository::TEXT_FILTERS as $field) {
            $rules[$field] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    /**
     * Rejeita campo fora da whitelist em vez de deixá-lo virar um `where`.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $unknown = array_diff(array_keys($this->all()), self::allowedKeys());

                if ($unknown !== []) {
                    $validator->errors()->add(
                        'filters',
                        'Filtro não suportado: '.implode(', ', $unknown),
                    );
                }
            },
        ];
    }

    /**
     * Filtros já validados, sem os campos de paginação.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_diff_key($this->validated(), array_flip(['limit', 'page']));
    }

    public function perPage(): int
    {
        return (int) ($this->validated()['limit'] ?? GameRepository::DEFAULT_LIMIT);
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
