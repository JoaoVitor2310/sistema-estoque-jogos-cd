<?php

namespace App\Http\Controllers\Suppliers;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProspectSupplierRequest;
use App\UseCases\Suppliers\ProspectSupplierUseCase;
use Illuminate\Http\JsonResponse;

class SupplierController extends Controller
{
    public function __construct(
        private readonly ProspectSupplierUseCase $prospectSupplierUseCase,
    ) {}

    public function prospect(ProspectSupplierRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->prospectSupplierUseCase->execute(
            $data['supplier_steam_id'],
            $data['games'],
            $data['list_code'] ?? null,
        );

        return response()->json($result);
    }
}
