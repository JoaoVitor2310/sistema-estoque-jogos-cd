<?php

namespace App\Services;

use App\Models\Bundle;

class BundleService
{
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
