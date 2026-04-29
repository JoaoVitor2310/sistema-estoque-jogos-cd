<?php

namespace App\Http\Controllers;

use App\Services\FinancialService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinancialController extends Controller
{
    public function __construct(private readonly FinancialService $financialService) {}

    public function show(Request $request): Response
    {
        $year = (int) $request->get('year', now()->year);
        $month = (int) $request->get('month', now()->month);

        return Inertia::render('Financial', [
            'data' => $this->financialService->getDashboard($year, $month),
            'year' => $year,
            'month' => $month,
        ]);
    }
}
