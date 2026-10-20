<?php

namespace App\UseCases\Trades;

use App\Domain\Enums\TradeLineAuthority;
use App\Domain\Trades\GamivoIdentity;
use App\Domain\Trades\LineGamePair;
use App\Models\TradeLine;
use App\Services\Trades\TradeLineFlagsService;
use App\UseCases\Trades\DTO\TradeLineDTO;

class UpdateTradeLineUseCase
{
    /** As colunas que as regras derivadas do jogo precisam ler antes de gravar. */
    private const IDENTITY_COLUMNS = [...LineGamePair::COLUMNS, GamivoIdentity::DERIVED_COLUMN];

    public function __construct(
        private readonly TradeLineFlagsService $flagsService,
    ) {}

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

        $stored = $line->only(self::IDENTITY_COLUMNS);

        // O `gamivo_id` é derivado do par (`game_name`, `region`) — ver
        // [[App\Domain\Trades\GamivoIdentity]]. Trocado o par, o id guardado
        // aponta para outro produto, e a linha segue para o import sem que nada
        // mais denuncie isso.
        //
        // Vale para as **duas** autoridades, e isso não amplia o alcance do
        // supplier: ele continua sem escolher o que vai em `gamivo_id`. Apagar é
        // consequência de domínio de mudar a região, que ele alcança — e a
        // região é justamente o campo que ele corrige.
        if (GamivoIdentity::invalidatedBy($stored, $attributes)) {
            $attributes[GamivoIdentity::DERIVED_COLUMN] = null;
        }

        // As marcas ([[App\Domain\Trades\TradeLineFlags]]) também são do par:
        // trocou o jogo ou a região, a linha é outro jogo, e as marcas são
        // refeitas contra o estoque e os giveaways de agora. Pelo mesmo
        // raciocínio do id, vale para as duas autoridades — o supplier que
        // corrige a região muda de fato o grupo de estoque da key. A marca de
        // giveaway só depende do nome; refazê-la na troca de região é
        // inofensivo e mantém um gatilho só.
        if (LineGamePair::changedBy($stored, $attributes)) {
            // O patch é parcial: o que ele não traz continua como está gravado.
            $game = $attributes + $stored;

            $attributes = array_merge($attributes, $this->flagsService->flagsFor($game['game_name'], $game['region']));
        }

        $line->update($attributes);

        return $line;
    }
}
