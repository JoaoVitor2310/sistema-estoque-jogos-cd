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
     * @return Collection<int, array<string, mixed>>
     */
    public function allWithStockedStatus(): Collection
    {
        $trades = Trade::with('supplier')
            ->orderBy('created_at', 'desc')
            ->get(['id', 'title', 'games', 'date', 'tf2_qty', 'supplier_id', 'created_at']);

        $allKeyCodes = $trades
            ->flatMap(fn ($t) => collect($t->games ?? [])->pluck('keyCode')->filter())
            ->unique()
            ->values()
            ->all();

        $stockedCodes = empty($allKeyCodes)
            ? collect()
            : Key::whereIn('key_code', $allKeyCodes)->pluck('key_code')->flip();

        return $trades->map(function ($trade) use ($stockedCodes) {
            $isStocked = collect($trade->games ?? [])
                ->some(fn ($r) => isset($r['keyCode']) && $stockedCodes->has($r['keyCode']));

            return [
                'id' => $trade->id,
                'title' => $trade->title,
                'games' => $trade->games ?? [],
                'date' => $trade->date?->format('d/m/Y'),
                'tf2_qty' => $trade->tf2_qty,
                'supplier' => $trade->supplier ? ['url' => $trade->supplier->url] : null,
                'created_at' => $trade->created_at,
                'is_stocked' => $isStocked,
            ];
        });
    }

    /**
     * Verifica se ao menos um key_code dos jogos da trade já está no estoque.
     *
     * @param  array<int, array<string, mixed>>  $games
     */
    public function isStocked(array $games): bool
    {
        $keyCodes = collect($games)->pluck('keyCode')->filter()->values()->all();

        return ! empty($keyCodes) && Key::whereIn('key_code', $keyCodes)->exists();
    }
}
