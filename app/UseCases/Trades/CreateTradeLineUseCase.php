<?php

namespace App\UseCases\Trades;

use App\Models\Trade;
use App\Models\TradeLine;
use App\UseCases\Trades\DTO\TradeLineDTO;
use Illuminate\Support\Facades\DB;

class CreateTradeLineUseCase
{
    /**
     * Insere uma linha na trade. Sem `position`, entra no fim; com `position`,
     * entra naquela posição e empurra as seguintes — é assim que duplicar uma
     * linha a coloca logo abaixo da original.
     */
    public function execute(Trade $trade, TradeLineDTO $data): TradeLine
    {
        return DB::transaction(function () use ($trade, $data) {
            $end = $this->nextPosition($trade);

            // Grampeado no fim: uma posição além dele abriria um buraco na
            // numeração, e são as posições contíguas que fazem "logo abaixo
            // desta" significar `position + 1` sem reconsultar o banco.
            $position = min($data->position ?? $end, $end);

            // Abrir espaço e inserir precisam ser atômicos: entre um e outro, a
            // trade teria duas linhas disputando a mesma posição.
            $trade->lines()
                ->where('position', '>=', $position)
                ->increment('position');

            return $trade->lines()->create(
                $data->toAttributes() + ['position' => $position],
            );
        });
    }

    private function nextPosition(Trade $trade): int
    {
        $last = $trade->lines()->max('position');

        return $last === null ? 0 : ((int) $last) + 1;
    }
}
