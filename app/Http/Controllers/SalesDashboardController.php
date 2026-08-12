<?php

namespace App\Http\Controllers;

use App\Services\Sales\SalesDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SalesDashboardController extends Controller
{
    public function __construct(private readonly SalesDashboardService $salesDashboardService) {}

    public function show(Request $request): Response
    {
        $year = (int) $request->get('year', now()->year);
        $month = (int) $request->get('month', now()->month);

        return Inertia::render('SalesDashboard', [
            'data' => $this->salesDashboardService->getDashboard($year, $month),
            'year' => $year,
            'month' => $month,
        ]);
    }
}
