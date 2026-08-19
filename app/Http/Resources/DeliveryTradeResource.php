<?php

namespace App\Http\Resources;

use App\Domain\Enums\TradeLineAuthority;
use App\Models\TradeLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A trade como o **supplier** a enxerga.
 *
 * Contraparte de leitura de [[App\Services\Trades\DeliveryReadModel]], que faz o
 * `select` — aqui é o formato. É o **único** ponto por onde dado da trade sai
 * para o supplier: as rotas de escrita respondem vazias de propósito, porque
 * devolver o registro fresco depois de salvar é o atalho por onde o vazamento de
 * preço costuma escapar.
 *
 * Sem `title` e sem nada do supplier: o título é anotação interna da equipe, e a
 * página não confirma a identidade de quem recebeu o link. A identidade que ela
 * exibe é a **nossa** — marca e contagem de jogos —, porque um
 * formulário anônimo pedindo `key_code` é indistinguível de phishing.
 */
class DeliveryTradeResource extends JsonResource
{
    /**
     * Sem o envelope `data`: a página é servida por Inertia, e a prop `trade`
     * carrega a trade — não uma caixa com a trade dentro.
     */
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'tf2_qty' => $this->tf2_qty,
            'supplier_notes' => $this->supplier_notes,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'lines' => $this->lines->map(fn (TradeLine $line) => [
                'id' => $line->id,
                'game_name' => $line->game_name,
                // Contexto para ele responder a região: é o bundle que costuma
                // carregar o region lock. Só leitura.
                'bundle' => $line->bundle,
                'region' => $line->region,
                // Mesma forma que a escrita aceita de volta, no formato que
                // ele lê: a página é em inglês.
                'expires_at' => $line->expires_at?->format(TradeLineAuthority::Supplier->dateFormat()),
                'key_code' => $line->key_code,
            ])->all(),
        ];
    }
}
