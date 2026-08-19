<?php

namespace App\Http\Requests;

use App\Domain\Enums\ClaimType;
use App\Domain\Enums\KeyFormat;
use App\Domain\Enums\PresenceFilter;
use App\Domain\Enums\SellPlatform;
use App\Domain\Keys\GuestKeyVisibility;
use App\Services\Keys\KeyRepository;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Whitelist de filtros de POST /keys/search.
 *
 * A rota foi pública até 2026-08-19 e o visitante recebia só um subconjunto das
 * colunas ([[App\Domain\Keys\GuestKeyVisibility]]); hoje ela exige equipe, e o
 * escopo abaixo continua valendo como segunda barreira.
 *
 * Mascarar apenas a saída não bastava: o total de resultados respondia "existe
 * key com esse key_code?", e repetir a
 * pergunta com prefixos crescentes enumera o código da chave — o próprio
 * produto vendido. Por isso a whitelist é escopada pelo gate can-edit, e
 * filtrar por coluna que o visitante não enxerga devolve 403 em vez de ser
 * silenciosamente ignorado (ignorar mentiria sobre o resultado).
 *
 * Antes, o filtro saía de `$request->except('page')` — qualquer nome de coluna
 * virava um `where`. Além do vazamento, isso deixava um 500 latente: `limit`
 * enviado no corpo virava `where` sobre coluna inexistente.
 */
class IndexKeysRequest extends FormRequest
{
    /**
     * Filtros liberados para visitante não autenticado.
     *
     * Derivado das colunas que ele já recebe na resposta, nunca escrito à mão:
     * uma segunda lista manual acabaria divergindo de GuestKeyVisibility, e
     * divergir aqui reabre exatamente o vazamento que esta classe fecha.
     *
     * @return string[]
     */
    public static function guestFilters(): array
    {
        return array_merge(
            KeyRepository::filtersFor(GuestKeyVisibility::FIELDS),
            ['limit', 'page'],
        );
    }

    /**
     * Todo nome de campo aceito pelo endpoint: os filtros que o repository
     * sabe aplicar, mais os dois campos de paginação.
     *
     * @return string[]
     */
    public static function allowedKeys(): array
    {
        return array_merge(KeyRepository::allowedFilters(), ['limit', 'page']);
    }

    /**
     * Descarta os campos vazios antes de validar e de autorizar.
     *
     * O buildSearchPayload() do Keys.vue envia o formulário inteiro a cada
     * busca, inclusive os campos que o usuário não preencheu — sem isso, um
     * visitante levaria 403 numa busca legítima só porque o payload trouxe
     * `key_code: ''`.
     *
     * `0` e `'0'` são preservados de propósito: tratá-los como vazios é a
     * armadilha de falsy-zero já catalogada em docs/IMPROVEMENTS.md.
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
        if (Gate::allows('can-edit')) {
            return true;
        }

        return array_diff(array_keys($this->all()), self::guestFilters()) === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.KeyRepository::MAX_LIMIT],
            'claim_type' => ['nullable', 'array'],
            'claim_type.*' => [Rule::enum(ClaimType::class)],
            'key_format' => ['nullable', 'array'],
            'key_format.*' => [Rule::enum(KeyFormat::class)],
            'sell_platform' => ['nullable', 'array'],
            'sell_platform.*' => [Rule::enum(SellPlatform::class)],
        ];

        foreach (KeyRepository::TEXT_FILTERS as $field) {
            $rules[$field] = ['nullable', 'string', 'max:255'];
        }

        foreach (KeyRepository::DATE_RANGE_FILTERS as $field) {
            $rules[$field.'_from'] = ['nullable', 'date_format:Y-m-d'];
            $rules[$field.'_to'] = ['nullable', 'date_format:Y-m-d'];
        }

        foreach (array_keys(KeyRepository::PRESENCE_FILTERS) as $field) {
            $rules[$field] = ['nullable', Rule::enum(PresenceFilter::class)];
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
        return (int) ($this->validated()['limit'] ?? KeyRepository::DEFAULT_LIMIT);
    }
}
