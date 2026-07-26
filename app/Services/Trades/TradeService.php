<?php

namespace App\Services\Trades;

use App\Models\Trade;
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
        $query = Trade::with('supplier')
            ->select(['id', 'title', 'games', 'date', 'tf2_qty', 'supplier_id', 'created_at', 'message_sent', 'is_imported']);

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

        // Busca no JSON `games` como texto. Não restringimos ao campo `name`
        // porque Postgres jsonb re-serializa com espaço após o dois-pontos
        // (`"name": "…"`) e SQLite/Laravel serializam sem espaço (`"name":"…"`),
        // então um padrão pinado quebra em prod. Na prática o falso positivo é
        // desprezível: key_codes seguem `XXXXX-XXXXX-XXXXX`, gamivo_id é numérico
        // — não colidem com nomes de jogo reais.
        $lower = strtolower(trim($needle));
        $query->whereRaw('LOWER(CAST(games AS TEXT)) LIKE ?', ['%'.$lower.'%']);
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
            'games' => $trade->games ?? [],
            'date' => $trade->date?->format('d/m/Y'),
            'tf2_qty' => $trade->tf2_qty,
            'supplier' => $trade->supplier ? ['url' => $trade->supplier->url] : null,
            'created_at' => $trade->created_at,
            'message_sent' => (bool) $trade->message_sent,
            'is_imported' => (bool) $trade->is_imported,
        ];
    }
}
