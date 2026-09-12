<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddBundleGamesRequest;
use App\Http\Requests\RemoveBundleGamesRequest;
use App\Http\Requests\StoreBundleRequest;
use App\Models\Bundle;
use App\Services\Bundles\BundleService;
use App\Traits\HttpResponses;
use App\UseCases\Bundles\AddGamesToBundleUseCase;
use App\UseCases\Bundles\CreateBundleUseCase;
use App\UseCases\Bundles\ResearchBundleGamesUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class BundleController extends Controller
{
    use HttpResponses;

    public function __construct(
        private readonly BundleService $bundleService,
        private readonly CreateBundleUseCase $createBundleUseCase,
        private readonly AddGamesToBundleUseCase $addGamesToBundleUseCase,
        private readonly ResearchBundleGamesUseCase $researchBundleGamesUseCase,
    ) {}

    public function index(Request $request)
    {
        $filters = $request->except('page');
        $bundles = $this->bundleService->getBundlesWithFilters($filters);

        $paginationData = [
            'current_page' => $bundles->currentPage(),
            'last_page' => $bundles->lastPage(),
            'per_page' => $bundles->perPage(),
            'total' => $bundles->total(),
            'from' => $bundles->firstItem(),
            'to' => $bundles->lastItem(),
        ];

        // Se for uma requisição AJAX, retorna JSON
        if ($request->expectsJson() || $request->wantsJson()) {
            return $this->response(200, 'Pesquisa realizada com sucesso.', [
                'bundles' => $bundles->items(),
                'totalBundles' => $bundles->total(),
                'pagination' => $paginationData,
            ]);
        }

        return Inertia::render('Bundles', [
            'bundles' => $bundles->items(),
            'pagination' => $paginationData,
        ]);
    }

    public function store(StoreBundleRequest $request)
    {
        try {
            $bundle = $this->createBundleUseCase->execute($request->validated());
        } catch (\Exception $e) {
            Log::error('Erro ao criar bundle: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return $this->error(500, 'Erro interno ao cadastrar bundle novo.', [$e->getMessage()]);
        }

        return $this->response(201, 'Bundle cadastrado com sucesso', $bundle);
    }

    public function addGames(AddBundleGamesRequest $request, Bundle $bundle)
    {
        try {
            $result = $this->addGamesToBundleUseCase->execute($bundle, $request->gameIds());
        } catch (\Exception $e) {
            Log::error('Erro ao adicionar jogos ao bundle', [$e->getMessage()]);

            return $this->error(500, 'Erro interno ao adicionar jogos ao bundle', [$e->getMessage()]);
        }

        if (! $result['added']) {
            return $this->error(400, 'O jogo selecionado já está no bundle');
        }

        return $this->response(200, 'Jogos adicionados ao bundle com sucesso', $result['bundle']);
    }

    public function removeGames(RemoveBundleGamesRequest $request, Bundle $bundle)
    {
        $bundle->games()->detach($request->gameIds());

        return $this->response(200, 'Jogos removidos do bundle com sucesso', $bundle);
    }

    /**
     * Dispara a pesquisa de preço/popularidade dos jogos do bundle.
     *
     * Responde 202 porque o price_researcher só enfileira: a trade com os jogos
     * do bundle nasce depois, quando o resultado chega pelo callback.
     */
    public function research(Bundle $bundle): JsonResponse
    {
        $result = $this->researchBundleGamesUseCase->execute($bundle);

        if (! $result['success']) {
            return $this->error($result['code'], $result['message'], [], $result['data']);
        }

        return $this->response(202, 'Pesquisa dos jogos do bundle enfileirada.', $result['data']);
    }

    public function destroy(Bundle $bundle)
    {
        try {
            $bundle->delete();
        } catch (\Exception $e) {
            Log::error('Erro ao deletar bundle', [$e->getMessage()]);

            return $this->error(500, 'Erro interno ao deletar bundle', [$e->getMessage()]);
        }

        return $this->response(200, 'Bundle deletado com sucesso', $bundle);
    }

    public function update(StoreBundleRequest $request, Bundle $bundle)
    {
        try {
            $bundle->fill($request->validated());
            $bundle->save();
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar bundle', [$e->getMessage()]);

            return $this->error(500, 'Erro interno ao atualizar bundle', [$e->getMessage()]);
        }

        return $this->response(200, 'Bundle atualizado com sucesso', $bundle);
    }
}
