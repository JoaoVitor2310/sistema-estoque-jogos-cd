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
        // `lines` eager loaded: sem isso, apresentar 40 trades dispara 40 queries.
        $query = Trade::with(['supplier', 'lines'])
            ->select(['id', 'title', 'date', 'tf2_qty', 'supplier_id', 'created_at', 'message_sent', 'is_imported']);

        $this->applyViewFilter($query, $filters['view'] ?? self::VIEW_OPEN);
        $this->applyDateRange($query, $filters['date_from'] ?? null, $filters['date_to'] ?? null);
        $this->applyTf2Range($query, $filters['tf2_min'] ?? null, $filters['tf2_max'] ?? null);
        $this->applyTitleSearch($query, $filters['title_search'] ?? null);
        $this->applySupplierSearch($query, $filters['supplier_search'] ?? null);
        $this->applyGameSearch($query, $filters['game_search'] ?? null);
        $this->applySort($query, $sortField, $sortDir);

        return $query->paginate($perPage)->through(fn (Trade $trade) => $this->presentTrade($trade));
    }

    private function applyViewFilter(Builder $query, string $view): void
    {
        match ($view) {
            self::VIEW_IMPORTED => $query->where('is_imported', true),
            self::VIEW_ALL => null,
            default => $query->where('is_imported', false),
        };
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
            'lines' => $trade->lines->map(fn (TradeLine $line) => $this->presentLine($line))->all(),
            'date' => $trade->date?->format('d/m/Y'),
            'tf2_qty' => $trade->tf2_qty,
            'supplier' => $trade->supplier ? ['url' => $trade->supplier->url] : null,
            'created_at' => $trade->created_at,
            'message_sent' => (bool) $trade->message_sent,
            'is_imported' => (bool) $trade->is_imported,
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
