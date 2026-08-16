<?php

namespace App\UseCases\Trades;

use App\Models\TradeLine;
use Illuminate\Support\Facades\DB;

class DeleteTradeLineUseCase
{
    /**
     * Remove a linha e fecha o buraco que ela deixou na ordem.
     *
     * Buraco na numeração não quebra a ordenação, que continua correta, mas
     * manter as posições contíguas é o que faz "logo abaixo desta" significar
     * `position + 1` sem consultar o banco antes.
     */
    public function execute(TradeLine $line): void
    {
        DB::transaction(function () use ($line) {
            $tradeId = $line->trade_id;
            $position = $line->position;

            $line->delete();

            TradeLine::where('trade_id', $tradeId)
                ->where('position', '>', $position)
                ->decrement('position');
        });
    }
}
