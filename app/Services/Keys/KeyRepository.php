<?php

namespace App\Services\Keys;

use App\Domain\Enums\PresenceFilter;
use App\Domain\Keys\KeyEligibility;
use App\Models\Key;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Queries complexas sobre a tabela keys.
 * Infraestrutura pura — sem lógica de negócio.
 */
class KeyRepository
{
    /** Multi-seleção: casa com qualquer um dos valores (whereIn). */
    public const LIST_FILTERS = ['claim_type', 'key_format', 'sell_platform'];

    /** Busca textual por substring, sem diferenciar maiúsculas. */
    public const TEXT_FILTERS = [
        'game_name',
        'region',
        'identified_platform',
        'key_code',
        'gamivo_id',
        'steam_id',
        'notes',
        'supplier_url',
        // varchar com rótulo legível ("2x TF2 Keys / 5"), montado por
        // SalePriceCalculator::tradeCostLabel — nunca foi coluna numérica.
        'total_paid',
    ];

    /** Colunas de data que aceitam range pelos sufixos _from / _to. */
    public const DATE_RANGE_FILTERS = ['acquired_at', 'listed_at', 'sold_at', 'expires_at'];

    /**
     * Filtros de presença: mapa `nome do filtro` => `coluna consultada`.
     *
     * O sufixo `_filled` separa presença de intervalo — `listed_at_filled`
     * pergunta se a coluna tem valor, `listed_at_from`/`_to` delimitam um
     * período. Sem o sufixo, o mesmo prefixo carregava os dois sentidos.
     *
     * O mapa também é o que permite derivar a whitelist do visitante a partir
     * das colunas que ele enxerga (ver filtersFor).
     */
    public const PRESENCE_FILTERS = [
        'listed_at_filled' => 'listed_at',
        'sold_at_filled' => 'sold_at',
        'expires_at_filled' => 'expires_at',
        'notes_filled' => 'notes',
        'gamivo_id_filled' => 'gamivo_id',
    ];

    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 500;

    /**
     * Todo nome de filtro que paginate() sabe aplicar.
     *
     * @return string[]
     */
    public static function allowedFilters(): array
    {
        $keys = array_merge(
            self::LIST_FILTERS,
            self::TEXT_FILTERS,
            array_keys(self::PRESENCE_FILTERS),
        );

        foreach (self::DATE_RANGE_FILTERS as $field) {
            $keys[] = $field.'_from';
            $keys[] = $field.'_to';
        }

        return array_values(array_unique($keys));
    }

    /**
     * Subconjunto de allowedFilters() que só toca as colunas informadas.
     *
     * Deriva a whitelist de quem enxerga um recorte da tabela (o visitante não
     * autenticado, ver GuestKeyVisibility) em vez de mantê-la escrita à mão em
     * paralelo — duas listas manuais divergem, e divergir aqui reabre o
     * vazamento.
     *
     * @param  string[]  $columns
     * @return string[]
     */
    public static function filtersFor(array $columns): array
    {
        $filters = [];

        foreach (array_merge(self::TEXT_FILTERS, self::LIST_FILTERS) as $field) {
            if (in_array($field, $columns, true)) {
                $filters[] = $field;
            }
        }

        foreach (self::DATE_RANGE_FILTERS as $field) {
            if (in_array($field, $columns, true)) {
                $filters[] = $field.'_from';
                $filters[] = $field.'_to';
            }
        }

        foreach (self::PRESENCE_FILTERS as $filter => $column) {
            if (in_array($column, $columns, true)) {
                $filters[] = $filter;
            }
        }

        return array_values(array_unique($filters));
    }

    /**
     * Busca paginada de keys a partir dos filtros já validados pelo
     * IndexKeysRequest. Nenhum nome de coluna vem do request: as chaves são
     * comparadas contra as constantes da whitelist antes de virarem SQL.
     *
     * Ordem fixa por `id` desc: o endpoint não recebe critério de ordenação.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(
        array $filters,
        int $perPage = self::DEFAULT_LIMIT,
        bool $withSupplier = false,
    ): LengthAwarePaginator {
        $query = Key::query()->when($withSupplier, fn (Builder $q) => $q->with('supplier'));

        foreach ($filters as $field => $value) {
            $this->applyFilter($query, $field, $value);
        }

        return $query->orderBy('id', 'desc')->paginate($perPage);
    }

    private function applyFilter(Builder $query, string $field, mixed $value): void
    {
        if (in_array($field, self::LIST_FILTERS, true)) {
            $query->whereIn($field, (array) $value);

            return;
        }

        if (in_array($field, self::TEXT_FILTERS, true)) {
            // LOWER(col) LIKE — cross-DB (Postgres em prod, SQLite em teste).
            // O ILIKE anterior é exclusivo do Postgres, o que impedia qualquer
            // teste automatizado de exercitar este caminho.
            $query->whereRaw(
                'LOWER('.$field.') LIKE ?',
                ['%'.mb_strtolower(trim((string) $value)).'%'],
            );

            return;
        }

        if (str_ends_with($field, '_from')) {
            $query->whereDate(substr($field, 0, -5), '>=', $value);

            return;
        }

        if (str_ends_with($field, '_to')) {
            $query->whereDate(substr($field, 0, -3), '<=', $value);

            return;
        }

        // Presença/ausência.
        //
        // Em coluna de texto, string vazia conta como ausente — mesmo critério
        // de Key::scopeWithGamivoId, que trata '' como sem gamivo_id. Em coluna
        // de data, comparar com '' é erro de tipo no Postgres
        // ("invalid input syntax for type date"), então ali só cabe o teste de
        // nulo. O SQLite dos testes aceita as duas formas e não acusa a
        // diferença — ver o teste de bindings em KeySearchTest.
        $column = self::PRESENCE_FILTERS[$field];
        $isText = in_array($column, self::TEXT_FILTERS, true);

        if ($value === PresenceFilter::Filled->value) {
            $query->whereNotNull($column);

            if ($isText) {
                $query->where($column, '!=', '');
            }

            return;
        }

        $isText
            ? $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, ''))
            : $query->whereNull($column);
    }

    /**
     * Busca uma key pelo código de ativação.
     * Quando $excludeId é fornecido, ignora o próprio registro (útil no update).
     */
    public function findByKeyCode(string $keyCode, ?int $excludeId = null): ?Key
    {
        return Key::where('key_code', $keyCode)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->first();
    }

    /**
     * Retorna todas as keys vinculadas a uma trade (mesmo lote), ordenadas por id ASC.
     * Usado no recálculo do rateio de custo ao editar uma key. Ver docs/adr/0004.
     *
     * @return Collection<int, Key>
     */
    public function findByTradeId(int $tradeId): Collection
    {
        return Key::where('trade_id', $tradeId)
            ->orderBy('id')
            ->get();
    }

    /**
     * Retorna a key governante de um produto Gamivo: entre as listadas e ainda não
     * vendidas que compartilham o mesmo gamivo_id, a mais antiga na oferta — quem a
     * Gamivo vende primeiro (FIFO). Usada pelo UpdateOffersUseCase tanto para o clamp
     * de min_api/max_api quanto para o game_name do log, com uma query só.
     *
     * Ordena por listed_at ASC, com id ASC como desempate: listed_at é uma coluna
     * `date` (sem hora), então keys confirmadas no mesmo lote do AutoSellUseCase
     * empatam na mesma data — nesse caso, a de menor id foi enviada primeiro no
     * uploadKeys em lote (ver AutoSellUseCase::processGroup). Ver docs/adr/0006.
     *
     * min_api/max_api são NOT NULL desde 2026-08-08 — toda key nasce com os dois
     * calculados (RegisterKeyUseCase), então o retorno aqui sempre traz valores
     * concretos, nunca precisando de fallback.
     */
    public function findGoverningKeyByGamivoId(int $productId): ?Key
    {
        return Key::where('gamivo_id', (string) $productId)
            ->whereNotNull('listed_at')
            ->whereNull('sold_at')
            ->orderBy('listed_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * Retorna keys elegíveis para listagem automática no Gamivo (AutoSellUseCase).
     *
     * Regras aplicadas via local scopes (ver Key):
     *  - withGamivoId: gamivo_id preenchido
     *  - notYetListed: listed_at e sold_at nulas
     *  - notGiftLink: key_code sem URL
     *  - withoutRecentBundle: jogo fora de bundles dos últimos 21 dias
     *
     * Ordenadas por id ASC: a Gamivo vende FIFO (key mais antiga primeiro), e o
     * AutoSellUseCase agrupa por gamivo_id usando a key de menor id como governante.
     *
     * @return Collection<int, Key>
     */
    public function findEligibleForAutoSell(): Collection
    {
        return Key::query()
            ->withGamivoId()
            ->notYetListed()
            ->notGiftLink()
            ->withoutRecentBundle(KeyEligibility::BUNDLE_EXCLUSION_DAYS)
            ->with('game.latestBundle')
            ->orderBy('id')
            ->get();
    }
}
