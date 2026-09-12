<?php

namespace App\Services\Bundles;

use App\Domain\Bundles\BundleGameLookup;
use App\Domain\Games\GameNameNormalizer;
use App\Models\Bundle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BundleService
{
    /**
     * Atribui **todos** os jogos pesquisados a um bundle nomeado pelo título.
     *
     * Vale para a pesquisa disparada de um bundle: o `title` volta idêntico do
     * price_researcher, então sabemos de onde cada jogo veio em vez de adivinhar.
     * É mais confiável que `recentBundleByGameNames()` em dois casos que o
     * palpite por nome erra — bundle fora da janela recente, e jogo que o
     * AllKeyShop devolve com outro nome.
     *
     * Só quem não tem supplier pode usar: a lista comentada também manda
     * `title` (o nome da lista no SteamTrades), e lista chamada "Humble Choice"
     * não faz de todo jogo dela um jogo de bundle.
     *
     * @param  string[]  $gameNames
     * @return array<string, string> normalized game name → bundle name; vazio quando o título não nomeia bundle nenhum
     */
    public function bundleByTitle(?string $title, array $gameNames): array
    {
        if (! $title || $gameNames === []) {
            return [];
        }

        try {
            // Eloquent aplica o global scope de soft-delete — bundle apagado
            // não reivindica jogo nenhum.
            if (! Bundle::where('name', $title)->exists()) {
                return [];
            }

            return array_fill_keys(
                array_map([GameNameNormalizer::class, 'normalize'], $gameNames),
                $title,
            );
        } catch (\Throwable $e) {
            // Mesma postura de recentBundleByGameNames: o vínculo com bundle é
            // enfeite da linha, e perdê-lo não pode derrubar a criação da trade.
            Log::error('[BundleService] bundleByTitle failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * @param  string[]  $gameNames
     * @return array<string, string> normalized game name → bundle name (bundle mais recente vence)
     */
    public function recentBundleByGameNames(array $gameNames): array
    {
        if (empty($gameNames)) {
            return [];
        }

        try {
            $normalized = array_map([GameNameNormalizer::class, 'normalize'], $gameNames);

            $rows = DB::table('bundles')
                ->join('bundle_games', 'bundle_games.bundle_id', '=', 'bundles.id')
                ->join('games', 'games.id', '=', 'bundle_games.game_id')
                ->select('bundles.name as bundle_name', 'games.normalized_name')
                ->where('bundles.release_date', '>=', now()->subMonths(BundleGameLookup::RECENT_MONTHS))
                ->whereIn('games.normalized_name', $normalized)
                // Query builder não aplica o global scope de soft-delete — filtrar à mão.
                ->whereNull('bundles.deleted_at')
                ->whereNull('games.deleted_at')
                ->orderByDesc('bundles.release_date')
                ->get();

            $map = [];
            foreach ($rows as $row) {
                if (! isset($map[$row->normalized_name])) {
                    $map[$row->normalized_name] = $row->bundle_name;
                }
            }

            return $map;
        } catch (\Throwable $e) {
            Log::error('[BundleService] recentBundleByGameNames failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Get bundles with filters and pagination
     *
     * @param  array  $filters  Filters to apply
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getBundlesWithFilters(array $filters)
    {
        $query = Bundle::with([
            'games' => function ($query) {
                $query->orderBy('name', 'asc');
            },
        ]);

        $this->applyFilters($query, $filters);

        $limit = $filters['limit'] ?? 20;

        return $query->orderBy('id', 'desc')->paginate($limit);
    }

    /**
     * Apply filters to the query
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  Query builder instance
     * @param  array  $filters  Filters to apply
     */
    private function applyFilters($query, array $filters): void
    {
        foreach ($filters as $key => $value) {
            if (! $value) {
                continue;
            }

            if (is_array($value)) {
                $query->whereIn($key, $value);
            } elseif (is_string($value)) {
                $this->applyStringFilter($query, $key, $value);
            } elseif (is_bool($value) && str_starts_with($key, 'search_')) {
                $query->whereNull($key);
            } else {
                $query->where($key, $value);
            }
        }
    }

    /**
     * Apply string filters (range, search, etc)
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  Query builder instance
     * @param  string  $key  Filter key
     * @param  string  $value  Filter value
     */
    private function applyStringFilter($query, string $key, string $value): void
    {
        // Range filters (minimum values)
        if (in_array($key, ['release_date_start', 'minimum_price_tf2_min', 'price_dolar_min'])) {
            $actualKey = str_replace(['_start', '_min'], '', $key);
            $query->where($actualKey, '>=', $value);

            return;
        }

        // Range filters (maximum values)
        if (in_array($key, ['release_date_end', 'minimum_price_tf2_max', 'price_dolar_max'])) {
            $actualKey = str_replace(['_end', '_max'], '', $key);
            $query->where($actualKey, '<=', $value);

            return;
        }

        // Game name filter (relationship)
        if ($key === 'game_name') {
            $query->whereHas('games', function ($query) use ($value) {
                $query->where('name', 'ILIKE', '%'.$value.'%');
            });

            return;
        }

        // Default string filter (LIKE)
        $query->where($key, 'ILIKE', '%'.$value.'%');
    }
}
