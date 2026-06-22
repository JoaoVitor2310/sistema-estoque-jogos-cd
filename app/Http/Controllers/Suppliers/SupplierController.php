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
        $result = $this->prospectSupplierUseCase->execute(
            $request->validated('supplier'),
            $request->validated('games'),
        );

        return response()->json($result);
    }
}
