<?php

namespace App\Services\Trades;

use App\Models\Trade;

/**
 * A consulta que alimenta a página do supplier.
 *
 * Existe como classe própria por um motivo de segurança, não de organização: a
 * página da entrega **nunca** pode devolver `market_price`, `popularity` ou
 * `gamivo_id`. Isso é a saída do `price_researcher` — quanto cada jogo dele
 * vale para nós —, e um supplier que enxerga esse número renegocia toda trade
 * futura com ele na mão. O vazamento não seria para um atacante eventual, seria
 * para **todo** supplier, sempre.
 *
 * Por isso a projeção é uma lista explícita de colunas e não `all()`: coluna
 * proibida não chega a sair do banco, e uma coluna nova em `trade_lines` não
 * entra aqui sem alguém digitar o nome dela. Ver docs/adr/0008.
 */
final class DeliveryReadModel
{
    /**
     * `trade_id` e `position` não são exibidos: o primeiro é o que permite ao
     * Eloquent ligar a linha à trade, o segundo é a ordem de exibição.
     *
     * `bundle` **é** exibido, e é o único campo do `price_researcher` que sai
     * daqui (decisão de 2026-08-19, ver docs/adr/0008): o bundle de origem é o
     * que costuma carregar o region lock da key, e sem ele o campo de região
     * pede uma informação que nem sempre está à mão de quem responde. É leitura
     * e só: `TradeLineAuthority::Supplier` continua sem alcançá-lo na escrita.
     */
    public const LINE_COLUMNS = [
        'id', 'trade_id', 'position', 'game_name', 'bundle', 'region', 'expires_at', 'key_code',
    ];

    /**
     * Carrega as linhas da trade já projetadas.
     *
     * `setRelation` em vez de `load`: `load` não recarrega uma relação já
     * carregada, e a trade pode chegar aqui com `lines` populado por outro
     * caminho — sem projeção nenhuma.
     */
    public function loadLines(Trade $trade): Trade
    {
        $trade->setRelation(
            'lines',
            $trade->lines()->select(self::LINE_COLUMNS)->get(),
        );

        return $trade;
    }
}
