<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordMovementRequest;
use App\Http\Requests\StoreFinancialMonthRequest;
use App\Models\FinancialMonth;
use App\Services\Financial\FinancialMonthService;
use App\Traits\HttpResponses;
use App\UseCases\Financial\CloseMonthUseCase;
use App\UseCases\Financial\CreateDraftFinancialMonthUseCase;
use App\UseCases\Financial\RecordMovementUseCase;
use App\UseCases\Financial\ReopenFinancialMonthUseCase;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class FinancialMonthController extends Controller
{
    use HttpResponses;

    public function __construct(
        private readonly FinancialMonthService $financialMonthService,
        private readonly CreateDraftFinancialMonthUseCase $createDraftFinancialMonthUseCase,
        private readonly RecordMovementUseCase $recordMovementUseCase,
        private readonly CloseMonthUseCase $closeMonthUseCase,
        private readonly ReopenFinancialMonthUseCase $reopenFinancialMonthUseCase,
    ) {}

    public function index(): Response
    {
        return Inertia::render('FinancialMonths', $this->financialMonthService->overview());
    }

    public function store(StoreFinancialMonthRequest $request): JsonResponse
    {
        try {
            $month = $this->createDraftFinancialMonthUseCase->execute($request->toDTO());
        } catch (\RuntimeException) {
            return $this->error(422, 'O primeiro fechamento mensal já foi aberto.');
        }

        return $this->response(201, 'Fechamento mensal criado.', $month);
    }

    public function storeMovement(RecordMovementRequest $request): JsonResponse
    {
        try {
            $recorded = $this->recordMovementUseCase->execute($request->toDTO());
        } catch (\InvalidArgumentException $e) {
            return $this->error(422, $e->getMessage());
        } catch (\RuntimeException) {
            return $this->error(422, 'Não há fechamento mensal em aberto.');
        }

        return $this->response(201, 'Movimento lançado.', $recorded);
    }

    public function close(): JsonResponse
    {
        try {
            $month = $this->closeMonthUseCase->execute();
        } catch (\RuntimeException) {
            return $this->error(422, 'Não há fechamento mensal em aberto.');
        }

        return $this->response(201, 'Mês fechado.', $month->load('movements'));
    }

    public function reopen(FinancialMonth $financialMonth): JsonResponse
    {
        try {
            $month = $this->reopenFinancialMonthUseCase->execute($financialMonth);
        } catch (\RuntimeException) {
            return $this->error(422, 'Só o fechamento mais recente pode ser reaberto.');
        }

        return $this->response(200, 'Mês reaberto.', $month);
    }
}
