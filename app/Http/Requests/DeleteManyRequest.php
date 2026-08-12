<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base das exclusões em lote.
 *
 * As telas enviam o array de linhas selecionadas do DataTable — objetos
 * completos, não ids — como query params de um DELETE, cada uma sob uma chave
 * própria (`games`, `assets`, `taxas`, `items`). Daí o `itemsKey()`.
 *
 * A existência dos ids é validada **aqui**, antes de qualquer escrita. Os
 * controllers checavam registro a registro dentro do loop de exclusão: um id
 * inválido no meio do lote abortava com erro depois de já ter apagado os
 * anteriores, e a tela recebia uma falha sobre um estado que mudara em parte.
 * Validar na fronteira e apagar num `whereIn` só torna o lote atômico por
 * construção, sem precisar de transação.
 */
abstract class DeleteManyRequest extends FormRequest
{
    /** Chave sob a qual a tela envia as linhas selecionadas. */
    abstract protected function itemsKey(): string;

    /** Tabela contra a qual os ids são conferidos. */
    abstract protected function table(): string;

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
            $this->itemsKey() => ['required', 'array', 'min:1'],
            $this->itemsKey().'.*.id' => ['required', 'integer', 'exists:'.$this->table().',id'],
        ];
    }

    /**
     * Ids validados, sem os demais campos da linha que a tela mandou junto.
     *
     * @return int[]
     */
    public function ids(): array
    {
        return array_map(
            static fn (array $item) => (int) $item['id'],
            $this->validated()[$this->itemsKey()],
        );
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
