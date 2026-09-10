<?php

namespace App\UseCases\Trades;

use App\Domain\Enums\TradeLineAuthority;
use App\Domain\Trades\GamivoIdentity;
use App\Models\TradeLine;
use App\UseCases\Trades\DTO\TradeLineDTO;

class UpdateTradeLineUseCase
{
    /** As colunas que a regra do id derivado precisa ler antes de gravar. */
    private const IDENTITY_COLUMNS = [...GamivoIdentity::IDENTIFYING_COLUMNS, GamivoIdentity::DERIVED_COLUMN];

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

        if ($attributes === []) {
            return $line;
        }

        // O `gamivo_id` é derivado do par (`game_name`, `region`) — ver
        // [[App\Domain\Trades\GamivoIdentity]]. Trocado o par, o id guardado
        // aponta para outro produto, e a linha segue para o import sem que nada
        // mais denuncie isso.
        //
        // Vale para as **duas** autoridades, e isso não amplia o alcance do
        // supplier: ele continua sem escolher o que vai em `gamivo_id`. Apagar é
        // consequência de domínio de mudar a região, que ele alcança — e a
        // região é justamente o campo que ele corrige.
        if (GamivoIdentity::invalidatedBy($line->only(self::IDENTITY_COLUMNS), $attributes)) {
            $attributes[GamivoIdentity::DERIVED_COLUMN] = null;
        }

        $line->update($attributes);

        return $line;
    }
}
