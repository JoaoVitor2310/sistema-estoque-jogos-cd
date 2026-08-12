<?php

namespace App\Http\Controllers\Suppliers;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProspectSupplierRequest;
use App\Http\Requests\SaveSupplierRequest;
use App\Models\Supplier;
use App\Traits\HttpResponses;
use App\UseCases\Suppliers\ExecuteSupplierListUseCase;
use App\UseCases\Suppliers\FindNewSuppliersUseCase;
use App\UseCases\Suppliers\ProspectSupplierUseCase;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    use HttpResponses;

    public function __construct(
        private readonly ProspectSupplierUseCase $prospectSupplierUseCase,
        private readonly ExecuteSupplierListUseCase $executeSupplierListUseCase,
        private readonly FindNewSuppliersUseCase $findNewSuppliersUseCase,
    ) {}

    public function index(): Response
    {
        $suppliers = Supplier::orderBy('name')->get();

        return Inertia::render('Suppliers', [
            'suppliers' => $suppliers,
        ]);
    }

    public function store(SaveSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::create($request->validated());

        return response()->json($supplier, 201);
    }

    public function update(SaveSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier->update($request->validated());

        return response()->json($supplier);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $supplier->delete();

        return response()->json(['message' => 'Fornecedor deletado.'], 200);
    }

    public function executeList(Supplier $supplier): JsonResponse
    {
        $result = $this->executeSupplierListUseCase->execute($supplier);

        if (! $result['success']) {
            if (isset($result['data'])) {
                return $this->error($result['code'], $result['message'], $result['data']);
            }

            return $this->error($result['code'], $result['message']);
        }

        return $this->response(200, $result['message'], $result['data']);
    }

    public function findNewSuppliers(): JsonResponse
    {
        $result = $this->findNewSuppliersUseCase->execute();

        if (! $result['success']) {
            return $this->error($result['code'], $result['message'], [], $result['data']);
        }

        return $this->response(202, 'Busca de novos fornecedores enfileirada.', $result['data']);
    }

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
