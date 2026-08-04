<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\DistributeToPartnersRequest;
use App\Http\Requests\RecordMovementRequest;
use App\Http\Requests\RecordTf2AllocationRequest;
use App\Http\Requests\RecordTransferRequest;
use App\Http\Requests\StoreFinancialMonthRequest;
use App\Models\FinancialMonth;
use App\Models\FinancialMovement;
use App\Services\Financial\FinancialMonthService;
use App\Traits\HttpResponses;
use App\UseCases\Financial\CloseMonthUseCase;
use App\UseCases\Financial\CreateDraftFinancialMonthUseCase;
use App\UseCases\Financial\DeleteMovementGroupUseCase;
use App\UseCases\Financial\DistributeToPartnersUseCase;
use App\UseCases\Financial\RecordMovementUseCase;
use App\UseCases\Financial\RecordTf2AllocationUseCase;
use App\UseCases\Financial\RecordTransferUseCase;
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
        private readonly RecordTransferUseCase $recordTransferUseCase,
        private readonly RecordTf2AllocationUseCase $recordTf2AllocationUseCase,
        private readonly DistributeToPartnersUseCase $distributeToPartnersUseCase,
        private readonly DeleteMovementGroupUseCase $deleteMovementGroupUseCase,
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

    public function storeTransfer(RecordTransferRequest $request): JsonResponse
    {
        try {
            $legs = $this->recordTransferUseCase->execute($request->toDTO());
        } catch (\InvalidArgumentException $e) {
            return $this->error(422, $e->getMessage());
        } catch (\RuntimeException) {
            return $this->error(422, 'Não há fechamento mensal em aberto.');
        }

        return $this->response(201, 'Transferência lançada.', $legs);
    }

    public function storeTf2Allocation(RecordTf2AllocationRequest $request): JsonResponse
    {
        try {
            $legs = $this->recordTf2AllocationUseCase->execute($request->toDTO());
        } catch (\InvalidArgumentException $e) {
            return $this->error(422, $e->getMessage());
        } catch (\RuntimeException) {
            return $this->error(422, 'Não há fechamento mensal em aberto.');
        }

        return $this->response(201, 'Verba de TF2 alocada.', $legs);
    }

    public function storeDistribution(DistributeToPartnersRequest $request): JsonResponse
    {
        try {
            $legs = $this->distributeToPartnersUseCase->execute($request->toDTO());
        } catch (\InvalidArgumentException $e) {
            return $this->error(422, $e->getMessage());
        } catch (\RuntimeException) {
            return $this->error(422, 'Não há fechamento mensal em aberto.');
        }

        return $this->response(201, 'Distribuição lançada.', $legs);
    }

    /**
     * Apaga o lançamento inteiro a partir de qualquer uma de suas linhas — as
     * pernas de uma transferência somem juntas (ver `DeleteMovementGroupUseCase`).
     */
    public function destroyMovement(FinancialMovement $financialMovement): JsonResponse
    {
        try {
            $deleted = $this->deleteMovementGroupUseCase->execute($financialMovement);
        } catch (\InvalidArgumentException $e) {
            return $this->error(422, $e->getMessage());
        } catch (\RuntimeException) {
            return $this->error(422, 'Só um mês em aberto pode ter lançamentos apagados.');
        }

        return $this->response(200, 'Lançamento apagado.', ['deleted' => $deleted]);
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
