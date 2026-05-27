<?php

namespace App\Services\Trades;

use App\Models\Key;
use App\Models\Trade;
use Illuminate\Support\Collection;

class TradeService
{
    /**
     * Retorna todas as trades em ordem decrescente de criação,
     * com o campo `is_stocked` calculado em uma única query (sem N+1).
     *
     * `is_stocked = true` quando ao menos um key_code da trade
     * já existe na tabela `keys`.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function allWithStockedStatus(): Collection
    {
        $trades = Trade::orderBy('created_at', 'desc')->get(['id', 'title', 'rows', 'created_at']);

        $allKeyCodes = $trades
            ->flatMap(fn ($t) => collect($t->rows)->pluck('keyCode')->filter())
            ->unique()
            ->values()
            ->all();

        $stockedCodes = empty($allKeyCodes)
            ? collect()
            : Key::whereIn('key_code', $allKeyCodes)->pluck('key_code')->flip();

        return $trades->map(function ($trade) use ($stockedCodes) {
            $isStocked = collect($trade->rows)
                ->some(fn ($r) => isset($r['keyCode']) && $stockedCodes->has($r['keyCode']));

            return array_merge($trade->only(['id', 'title', 'rows', 'created_at']), [
                'is_stocked' => $isStocked,
            ]);
        });
    }

    /**
     * Verifica se ao menos um key_code das rows da trade já está no estoque.
     * Chamado após o autosave para atualizar o indicador visual no frontend.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function isStocked(array $rows): bool
    {
        $keyCodes = collect($rows)->pluck('keyCode')->filter()->values()->all();

        return ! empty($keyCodes) && Key::whereIn('key_code', $keyCodes)->exists();
    }
}
