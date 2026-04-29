<?php

namespace App\Services;

use App\Models\Key;
use Illuminate\Support\Carbon;

class FinancialService
{
    /**
     * @param  int  $month  0 = ano completo, 1–12 = mês específico
     */
    public function getDashboard(int $year, int $month): array
    {
        return [
            'monthly_sales' => $this->getMonthlySales($year, $month),
            'monthly_purchases' => $this->getMonthlyPurchases($year, $month),
            'tf2_spent' => $this->getTf2Spent($year, $month),
            'stock' => $this->getStockSummary(),
            'sold_games' => $this->getSoldGames($year, $month),
            'trend' => $this->getMonthlyTrend(),
        ];
    }

    private function getMonthlySales(int $year, int $month): array
    {
        $result = Key::whereNotNull('sold_at')
            ->whereYear('sold_at', $year)
            ->when($month > 0, fn ($q) => $q->whereMonth('sold_at', $month))
            ->selectRaw('
                COUNT(*) as count,
                COALESCE(SUM(sold_price), 0) as gross_revenue,
                COALESCE(SUM(sale_profit), 0) as net_profit,
                COALESCE(AVG(sale_profit_percent), 0) as avg_margin
            ')
            ->first();

        return [
            'count' => (int) $result->count,
            'gross_revenue' => round((float) $result->gross_revenue, 2),
            'net_profit' => round((float) $result->net_profit, 2),
            'avg_margin' => round((float) $result->avg_margin, 1),
        ];
    }

    private function getMonthlyPurchases(int $year, int $month): array
    {
        $result = Key::whereNotNull('acquired_at')
            ->whereYear('acquired_at', $year)
            ->when($month > 0, fn ($q) => $q->whereMonth('acquired_at', $month))
            ->selectRaw('
                COUNT(*) as count,
                COALESCE(SUM(individual_cost), 0) as total_invested
            ')
            ->first();

        return [
            'count' => (int) $result->count,
            'total_invested' => round((float) $result->total_invested, 2),
        ];
    }

    private function getTf2Spent(int $year, int $month): float
    {
        $rows = Key::whereNotNull('acquired_at')
            ->whereNotNull('total_paid')
            ->whereNotNull('tf2_quantity')
            ->whereYear('acquired_at', $year)
            ->when($month > 0, fn ($q) => $q->whereMonth('acquired_at', $month))
            ->select('total_paid', 'acquired_at', 'tf2_quantity')
            ->get();

        return (float) $rows
            ->unique(fn ($k) => $k->total_paid . '|' . $k->acquired_at)
            ->sum('tf2_quantity');
    }

    private function getStockSummary(): array
    {
        $result = Key::whereNull('sold_at')
            ->selectRaw("
                COUNT(*) as total_count,
                COALESCE(SUM(individual_cost), 0) as total_invested,
                COALESCE(SUM(simulated_income), 0) as total_simulated,
                COUNT(CASE WHEN listed_at IS NOT NULL THEN 1 END) as listed_count,
                COUNT(CASE WHEN listed_at IS NULL THEN 1 END) as unlisted_count,
                COUNT(CASE WHEN expires_at <= NOW() + INTERVAL '30 days' AND expires_at > NOW() THEN 1 END) as expiring_count
            ")
            ->first();

        return [
            'total_count' => (int) $result->total_count,
            'total_invested' => round((float) $result->total_invested, 2),
            'total_simulated' => round((float) $result->total_simulated, 2),
            'listed_count' => (int) $result->listed_count,
            'unlisted_count' => (int) $result->unlisted_count,
            'expiring_count' => (int) $result->expiring_count,
        ];
    }

    private function getSoldGames(int $year, int $month): array
    {
        return Key::whereNotNull('sold_at')
            ->whereYear('sold_at', $year)
            ->when($month > 0, fn ($q) => $q->whereMonth('sold_at', $month))
            ->select('game_name', 'region', 'sold_price', 'sale_profit', 'sale_profit_percent', 'sold_at')
            ->orderByDesc('sale_profit')
            ->get()
            ->map(fn ($k) => [
                'game_name' => $k->game_name,
                'region' => $k->region,
                'sold_price' => round((float) $k->sold_price, 2),
                'sale_profit' => round((float) $k->sale_profit, 2),
                'sale_profit_percent' => round((float) $k->sale_profit_percent, 1),
                'sold_at' => $k->sold_at,
            ])
            ->values()
            ->toArray();
    }

    private function getMonthlyTrend(): array
    {
        $rows = Key::whereNotNull('sold_at')
            ->where('sold_at', '>=', Carbon::now()->subMonths(11)->startOfMonth())
            ->selectRaw("
                TO_CHAR(sold_at, 'YYYY-MM') as month,
                COUNT(*) as count,
                COALESCE(SUM(sold_price), 0) as gross_revenue,
                COALESCE(SUM(sale_profit), 0) as net_profit
            ")
            ->groupByRaw("TO_CHAR(sold_at, 'YYYY-MM')")
            ->orderByRaw("TO_CHAR(sold_at, 'YYYY-MM') ASC")
            ->get();

        return $rows->map(fn ($r) => [
            'month' => $r->month,
            'count' => (int) $r->count,
            'gross_revenue' => round((float) $r->gross_revenue, 2),
            'net_profit' => round((float) $r->net_profit, 2),
        ])->toArray();
    }
}
