<?php

namespace App\Services\Trades;

use App\Models\Trade;
use App\Models\TradeLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class TradeService
{
    public const PER_PAGE = 40;

    /**
     * Colunas permitidas para ordenação (whitelist).
     * `orderBy` genérico com input do usuário é vetor de SQL injection.
     */
    public const SORTABLE_FIELDS = ['date', 'tf2_qty'];

    /**
     * Views suportadas — controla o filtro sobre `is_imported`.
     */
    public const VIEW_OPEN = 'open';

    public const VIEW_IMPORTED = 'imported';

    public const VIEW_ALL = 'all';

    /**
     * A fila de conferência: entregue pelo supplier, ainda não importada.
     *
     * É um recorte de [[self::VIEW_OPEN]], não a view padrão — ela só tem
     * conteúdo entre o clique de entregar e o import, ou seja, fica vazia na
     * maior parte do tempo, e um default que se contorna toda vez é atrito. Em
     * Abertas essas trades vão para o topo; aqui elas aparecem sozinhas quando a
     * equipe quer só conferir. Ver docs/adr/0008.
     */
    public const VIEW_AWAITING_REVIEW = 'awaiting_review';

    /**
     * Retorna trades paginadas conforme filtros e ordenação.
     *
     * @param  array{
     *   view?: string,
     *   date_from?: ?string,
     *   date_to?: ?string,
     *   tf2_min?: ?string,
     *   tf2_max?: ?string,
     *   title_search?: ?string,
     *   supplier_search?: ?string,
     *   game_search?: ?string,
     * }  $filters
     */
    public function paginate(
        array $filters = [],
        string $sortField = 'date',
        string $sortDir = 'desc',
        int $perPage = self::PER_PAGE,
    ): LengthAwarePaginator {
        // A listagem **não** carrega as linhas, só quantas são: 40 trades abertas
        // somam ~2.500 linhas, e cada linha vira uma tabela editável de ~30
        // elementos na tela — 75 mil nós de DOM que ninguém pediu. As linhas vêm
        // por [[self::linesFor]] quando a trade é aberta.
        //
        // Sem lista de colunas: quem decide o que sai é `presentTrade`, coluna a
        // coluna. Uma segunda lista aqui não impediria vazamento nenhum — só
        // acrescentaria um lugar para esquecer, e esquecer produz coluna nula em
        // silêncio, não erro. A lista explícita da entrega existe por outro
        // motivo: lá ela impede a coluna proibida de **sair do banco**, porque o
        // leitor é o supplier (ver [[DeliveryReadModel]]).
        $query = Trade::with('supplier')->withCount('lines');

        $view = $filters['view'] ?? self::VIEW_OPEN;

        $this->applyViewFilter($query, $view);
        $this->applyDateRange($query, $filters['date_from'] ?? null, $filters['date_to'] ?? null);
        $this->applyTf2Range($query, $filters['tf2_min'] ?? null, $filters['tf2_max'] ?? null);
        $this->applyTitleSearch($query, $filters['title_search'] ?? null);
        $this->applySupplierSearch($query, $filters['supplier_search'] ?? null);
        $this->applyGameSearch($query, $filters['game_search'] ?? null);
        $this->pinAwaitingReview($query, $view);
        $this->applySort($query, $sortField, $sortDir);

        return $query->paginate($perPage)->through(fn (Trade $trade) => $this->presentTrade($trade));
    }

    /**
     * As linhas de uma trade, na projeção que a aba consome.
     *
     * Existe separado da listagem porque é o que a tela busca ao abrir a trade
     * — ver o comentário em [[self::paginate]].
     *
     * @return list<array<string, mixed>>
     */
    public function linesFor(Trade $trade): array
    {
        return $trade->lines()->get()->map(fn (TradeLine $line) => $this->presentLine($line))->all();
    }

    private function applyViewFilter(Builder $query, string $view): void
    {
        match ($view) {
            self::VIEW_IMPORTED => $query->where('is_imported', true),
            self::VIEW_ALL => null,
            self::VIEW_AWAITING_REVIEW => $this->scopeAwaitingReview($query),
            default => $query->where('is_imported', false),
        };
    }

    /**
     * A fila de conferência, como condição de query.
     *
     * Definição única: a view e a contagem do rótulo precisam concordar, e duas
     * cópias da mesma condição divergiriam na primeira vez que o critério mudar.
     */
    private function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('is_imported', false)->whereNotNull('delivered_at');
    }

    /**
     * Em Abertas, o que o supplier já entregou sobe para o topo.
     *
     * Só nessa view: em Importadas e em Todas a trade importada também tem
     * `delivered_at` preenchido, e o mesmo critério empurraria histórico para
     * cima. Em Aguardando conferência não há o que separar — a lista inteira é
     * a fila.
     *
     * `(delivered_at IS NULL) ASC` funciona nos dois bancos (Postgres em prod,
     * SQLite em teste): o falso ordena antes do verdadeiro nos dois, então a
     * linha com data vem primeiro.
     */
    private function pinAwaitingReview(Builder $query, string $view): void
    {
        if ($view !== self::VIEW_OPEN) {
            return;
        }

        $query->orderByRaw('(delivered_at IS NULL) ASC');
    }

    /**
     * Quantas trades esperam conferência — a contagem que o filtro exibe no
     * rótulo. É o que torna a fila impossível de não notar sem ela ser a view
     * padrão.
     */
    public function awaitingReviewCount(): int
    {
        return $this->scopeAwaitingReview(Trade::query())->count();
    }

    private function applyDateRange(Builder $query, ?string $from, ?string $to): void
    {
        if ($from) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('date', '<=', $to);
        }
    }

    private function applyTf2Range(Builder $query, ?string $min, ?string $max): void
    {
        if ($min !== null && $min !== '') {
            $query->where('tf2_qty', '>=', $min);
        }
        if ($max !== null && $max !== '') {
            $query->where('tf2_qty', '<=', $max);
        }
    }

    private function applyTitleSearch(Builder $query, ?string $needle): void
    {
        if ($needle === null || trim($needle) === '') {
            return;
        }

        // LOWER(...) LIKE LOWER(?) — cross-DB (Postgres em prod, SQLite em teste).
        $query->whereRaw('LOWER(title) LIKE ?', ['%'.strtolower(trim($needle)).'%']);
    }

    private function applySupplierSearch(Builder $query, ?string $needle): void
    {
        if ($needle === null || trim($needle) === '') {
            return;
        }

        $lower = strtolower(trim($needle));
        $query->whereHas('supplier', function (Builder $q) use ($lower) {
            $q->whereRaw('LOWER(url) LIKE ?', ['%'.$lower.'%']);
        });
    }

    private function applyGameSearch(Builder $query, ?string $needle): void
    {
        if ($needle === null || trim($needle) === '') {
            return;
        }

        // Busca só o nome da linha. Não há ganho de índice — o curinga à
        // esquerda impede btree —, o ganho é de precisão: varrer o documento
        // inteiro casava `key_code` e `gamivo_id` por acidente.
        // LOWER(...) LIKE ? — cross-DB (Postgres em prod, SQLite em teste).
        $lower = strtolower(trim($needle));
        $query->whereHas('lines', function (Builder $q) use ($lower) {
            $q->whereRaw('LOWER(game_name) LIKE ?', ['%'.$lower.'%']);
        });
    }

    private function applySort(Builder $query, string $field, string $dir): void
    {
        $field = in_array($field, self::SORTABLE_FIELDS, true) ? $field : 'date';
        $dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';

        // `id` como tiebreaker: várias trades no mesmo dia são o caso comum;
        // sem tiebreaker a ordem "chacoalha" entre requests com o mesmo filtro.
        $query->orderBy($field, $dir)->orderBy('id', 'desc');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTrade(Trade $trade): array
    {
        return [
            'id' => $trade->id,
            'title' => $trade->title,
            // Só a contagem: as linhas vêm por [[self::linesFor]] ao abrir.
            'lines_count' => (int) $trade->lines_count,
            'date' => $trade->date?->format('d/m/Y'),
            'tf2_qty' => $trade->tf2_qty,
            'supplier' => $trade->supplier ? ['url' => $trade->supplier->url] : null,
            'created_at' => $trade->created_at,
            'message_sent' => (bool) $trade->message_sent,
            'is_imported' => (bool) $trade->is_imported,
            // O estado da entrega é derivado, não uma coluna — ver
            // [[App\Domain\Enums\TradeDeliveryState]].
            'delivery_state' => $trade->deliveryState()->value,
            // O par que a equipe copia para o chat. Vai montado daqui porque
            // quem sabe a rota é o servidor; a aba só copia o que recebe. Sai
            // **só** nesta projeção — a da entrega não devolve token nenhum.
            'delivery_url' => $trade->delivery_uuid
                ? route('deliveries.show', ['trade' => $trade->delivery_uuid])
                : null,
            'delivery_token' => $trade->readableDeliveryToken(),
            'delivered_at' => $trade->delivered_at?->toIso8601String(),
            'supplier_notes' => $trade->supplier_notes,
        ];
    }

    /**
     * Projeção explícita por coluna, não `toArray()` do model: é o que impede
     * uma coluna nova de vazar na resposta sem alguém decidir que ela deve ir.
     *
     * `id` e `position` vão junto porque é por eles que a linha é endereçada
     * depois — `id` nas rotas de escrita, `position` para inserir uma cópia
     * logo abaixo.
     *
     * @return array<string, mixed>
     */
    private function presentLine(TradeLine $line): array
    {
        return [
            'id' => $line->id,
            'position' => $line->position,
            'game_name' => $line->game_name,
            'market_price' => $line->market_price,
            'popularity' => $line->popularity,
            'region' => $line->region,
            'bundle' => $line->bundle,
            // Validade sai como texto `dd/mm/aaaa`, igual à data da trade — é a
            // mesma forma que a escrita aceita de volta.
            'expires_at' => $line->expires_at?->format('d/m/Y'),
            'key_code' => $line->key_code,
            'gamivo_id' => $line->gamivo_id,
        ];
    }
}
