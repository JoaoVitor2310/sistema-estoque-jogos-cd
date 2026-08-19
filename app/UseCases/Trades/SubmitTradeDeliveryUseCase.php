<?php

namespace App\UseCases\Trades;

use App\Models\Trade;
use App\UseCases\Trades\DTO\DeliveryTradeDTO;

class SubmitTradeDeliveryUseCase
{
    /**
     * O que o supplier escreve na própria trade.
     *
     * Não recebe [[App\Domain\Enums\TradeLineAuthority]] porque não há aqui um
     * caminho compartilhado a restringir: a equipe grava os campos da trade por
     * [[UpdateTradeUseCase]], com outros campos. O escopo do supplier é a forma
     * do [[DeliveryTradeDTO]], que não sabe carregar outra coluna.
     *
     * Patch parcial, como a escrita de linha: o autosave dispara a cada tecla e
     * manda um campo por vez, então o que não veio não é tocado.
     */
    public function execute(Trade $trade, DeliveryTradeDTO $data): void
    {
        $attributes = $data->toAttributes();

        if ($attributes === []) {
            return;
        }

        $trade->update($attributes);
    }
}
