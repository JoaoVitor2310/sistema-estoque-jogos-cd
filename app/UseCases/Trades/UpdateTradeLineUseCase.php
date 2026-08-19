<?php

namespace App\UseCases\Trades;

use App\Domain\Enums\TradeLineAuthority;
use App\Models\TradeLine;
use App\UseCases\Trades\DTO\TradeLineDTO;

class UpdateTradeLineUseCase
{
    /**
     * Aplica um patch parcial numa linha: só as colunas que o payload trouxe
     * **e** que a autoridade alcança são tocadas.
     *
     * É o **caminho único de escrita de linha**. A entrega de trade pelo
     * supplier (ver docs/adr/0008) entra por aqui com
     * [[TradeLineAuthority::Supplier]] — em vez de existir um segundo caminho de
     * escrita para manter em dia.
     *
     * A autoridade é parâmetro obrigatório, sem valor padrão: um padrão faria
     * "quem está escrevendo" virar algo que se esquece de informar, e é
     * exatamente essa a pergunta que nenhuma chamada pode deixar implícita.
     */
    public function execute(TradeLine $line, TradeLineDTO $data, TradeLineAuthority $authority): TradeLine
    {
        $attributes = array_intersect_key(
            $data->toAttributes(),
            array_flip($authority->writableColumns()),
        );

        if ($attributes !== []) {
            $line->update($attributes);
        }

        return $line;
    }
}
