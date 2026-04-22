<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBundleRequest;
use App\Models\Bundle;
use App\Services\BundleService;
use App\Traits\HttpResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class BundleController extends Controller
{
    use HttpResponses;

    protected $bundleService;

    public function __construct(BundleService $bundleService)
    {
        $this->bundleService = $bundleService;
    }

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
        $data = $request->validated();
        $games = $data['games'] ?? [];

        try {
            return DB::transaction(function () use ($data, $games) {
                // Remove games do array principal pois não é campo da tabela bundles
                unset($data['games']);

                // Cria o bundle
                $created = Bundle::create($data);

                if ($created && ! empty($games)) {
                    // Associa os jogos ao bundle na tabela pivot
                    $created->games()->attach($games);

                    // Recarrega o bundle com os jogos para retornar completo
                    $created->load(['games' => function ($query) {
                        $query->orderBy('name', 'asc');
                    }]);
                }

                return $this->response(201, 'Bundle cadastrado com sucesso', $created);
            });
        } catch (\Exception $e) {
            Log::error('Erro ao criar bundle: '.$e->getMessage(), [
                'data' => $data,
                'games' => $games,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(500, 'Erro interno ao cadastrar bundle novo.', [$e->getMessage()]);
        }
    }

    public function addGames(Request $request, $bundleId)
    {
        try {
            $request->validate([
                'games' => 'required|array',
                'games.*' => 'exists:games,id',
            ]);

            $bundle = Bundle::findOrFail($bundleId);
            $gameIds = $request->input('games');

            $existingGameIds = $bundle->games()->whereIn('games.id', $gameIds)->pluck('games.id')->toArray();
            $newGameIds = array_diff($gameIds, $existingGameIds);

            // Se todos os jogos já estão no bundle
            if (empty($newGameIds)) {
                // Busca o nome do jogo para a mensagem
                return $this->error(400, 'O jogo selecionado já está no bundle');
            }

            // Adiciona os jogos ao bundle (sem duplicar)
            $bundle->games()->syncWithoutDetaching($gameIds);

            // Recarrega o bundle com os jogos atualizados
            $bundle->load(['games' => function ($query) {
                $query->orderBy('name', 'asc');
            }]);
        } catch (\Exception $e) {
            Log::error('Erro ao adicionar jogos ao bundle', [$e->getMessage()]);

            return $this->error(500, 'Erro interno ao adicionar jogos ao bundle', [$e->getMessage()]);
        }

        return $this->response(200, 'Jogos adicionados ao bundle com sucesso', $bundle);
    }

    public function removeGames(Request $request, $bundleId)
    {
        try {
            $bundle = Bundle::findOrFail($bundleId);
            $gameIds = $request->input('games');
            // dd($gameIds, $bundle);
            $bundle->games()->detach($gameIds);
        } catch (\Exception $e) {
            Log::error('Erro ao remover jogos do bundle', [$e->getMessage()]);

            return $this->error(500, 'Erro interno ao remover jogos do bundle', [$e->getMessage()]);
        }

        return $this->response(200, 'Jogos removidos do bundle com sucesso', $bundle);
    }

    public function destroy(string $id)
    {
        try {
            $bundle = Bundle::findOrFail($id);

            $result = $bundle->delete();
            if (! $result) {
                return $this->error(500, 'Erro interno ao deletar bundle');
            }
        } catch (\Exception $e) {
            Log::error('Erro ao deletar bundle', [$e->getMessage()]);

            return $this->error(500, 'Erro interno ao deletar bundle', [$e->getMessage()]);
        }

        return $this->response(200, 'Bundle deletado com sucesso', $bundle);
    }

    public function update(StoreBundleRequest $request, string $id)
    {
        try {
            $data = $request->validated();
            $bundle = Bundle::findOrFail($id);
            // dd($data, $id, $bundle);

            $result = $bundle->update($data);

            if (! $result) {
                return $this->error(500, 'Erro interno ao atualizar bundle');
            }
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar bundle', [$e->getMessage()]);

            return $this->error(500, 'Erro interno ao atualizar bundle', [$e->getMessage()]);
        }

        return $this->response(200, 'Bundle atualizado com sucesso', $bundle);
    }
}
