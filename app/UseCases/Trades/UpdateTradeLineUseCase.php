<?php

namespace App\UseCases\Trades;

use App\Models\TradeLine;
use App\UseCases\Trades\DTO\TradeLineDTO;

class UpdateTradeLineUseCase
{
    /**
     * Aplica um patch parcial numa linha: só as colunas que o payload trouxe
     * são tocadas.
     *
     * É o **caminho único de escrita de linha**. A entrega de trade pelo
     * supplier (ver docs/adr/0008) entra por aqui com um payload menor — o que
     * ele não pode mexer simplesmente não chega, em vez de existir um segundo
     * caminho de escrita para manter em dia.
     */
    public function execute(TradeLine $line, TradeLineDTO $data): TradeLine
    {
        $attributes = $data->toAttributes();

        if ($attributes !== []) {
            $line->update($attributes);
        }

        return $line;
    }
}
