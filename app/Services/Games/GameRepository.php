<?php

namespace App\Services\Games;

use App\Models\Game;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Queries de leitura e escrita simples sobre a tabela games.
 */
class GameRepository
{
    /** Busca textual por substring, sem diferenciar maiúsculas. */
    public const TEXT_FILTERS = ['name', 'region', 'gamivo_id', 'steam_id'];

    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 500;

    /**
     * Todo nome de filtro que paginate() sabe aplicar.
     *
     * @return string[]
     */
    public static function allowedFilters(): array
    {
        return self::TEXT_FILTERS;
    }

    /**
     * Busca paginada de jogos a partir dos filtros já validados pelo
     * IndexGamesRequest. Nenhum nome de coluna vem do request: as chaves são
     * comparadas contra a whitelist antes de virarem SQL.
     *
     * Ordem fixa por `id` desc: o endpoint não recebe critério de ordenação.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = self::DEFAULT_LIMIT): LengthAwarePaginator
    {
        $query = Game::with('bundles');

        foreach ($filters as $field => $value) {
            if (in_array($field, self::TEXT_FILTERS, true)) {
                // LOWER(col) LIKE — cross-DB (Postgres em prod, SQLite em teste).
                // ILIKE é exclusivo do Postgres e impede qualquer teste
                // automatizado de exercitar este caminho.
                $query->whereRaw(
                    'LOWER('.$field.') LIKE ?',
                    ['%'.mb_strtolower(trim((string) $value)).'%'],
                );
            }
        }

        return $query->orderBy('id', 'desc')->paginate($perPage);
    }

    /**
     * Retorna todos os jogos com steam_id cadastrado, para atualização de popularidade.
     *
     * @return Collection<int, Game>
     */
    public function getGamesForPopularityUpdate(): Collection
    {
        return Game::whereNotNull('steam_id')->get(['id', 'steam_id', 'name']);
    }

    /**
     * Atualiza a popularidade de um jogo no banco.
     */
    public function updatePopularity(int $id, int $popularity): void
    {
        Game::where('id', $id)->update(['popularity' => $popularity]);
    }
}
