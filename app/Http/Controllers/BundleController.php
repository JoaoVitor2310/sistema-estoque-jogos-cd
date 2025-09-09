<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBundleRequest;
use App\Models\Bundle;
use App\Models\Recursos;
use App\Traits\HttpResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class BundleController extends Controller
{
    use HttpResponses;

    public function index(Request $request)
    {
        $bundles = Bundle::with('games')->orderBy('id', 'asc')->get();
        // dd($bundles);

        // is_object($resources) ? $resources = $resources->toArray() : $resources; // Garante que sempre será um array, mesmo que tenha só um elemento

        //  return $this->response(200, 'resources encontrados com sucesso.', $resources);

        return Inertia::render('Bundles', [
            'bundles' => $bundles,
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

                if ($created && !empty($games)) {
                    // Associa os jogos ao bundle na tabela pivot
                    $created->games()->attach($games);

                    // Recarrega o bundle com os jogos para retornar completo
                    $created->load('games');
                }

                return $this->response(201, 'Bundle cadastrado com sucesso', $created);
            });
        } catch (\Exception $e) {
            Log::error('Erro ao criar bundle: ' . $e->getMessage(), [
                'data' => $data,
                'games' => $games,
                'trace' => $e->getTraceAsString()
            ]);

            return $this->error(500, 'Erro interno ao cadastrar bundle novo.', [$e->getMessage()]);
        }
    }

    // public function destroy(string $id)
    // {
    //     $resource = Recursos::select('*')->where('id', $id)->first();
    //     if (!$resource)
    //         return $this->error(404, 'Recurso não encontrado');


    //     $result = Recursos::where('id', $id)->delete();
    //     if (!$result)
    //         return $this->error(500, 'Erro interno ao deletar recurso');

    //     return $this->response(200, 'Recurso deletado com sucesso', $resource);
    // }

    // public function destroyArray(Request $request)
    // {
    //     $resources = $request->input('resources');
    //     if (!$resources)
    //         return $this->error(404, 'Recursos não enviadas', ['resources' => 'Recursos não enviadas']);

    //     foreach ($resources as $resource) {

    //         $item = Recursos::select('*')->where('id', $resource['id'])->first();
    //         if (!$item)
    //             return $this->error(404, 'Taxa não encontrada');

    //         $result = Recursos::where('id', $resource['id'])->delete();
    //         if (!$result)
    //             return $this->error(500, 'Erro interno ao deletar taxa');
    //     }

    //     return $this->response(200, 'Recursos deletadas com sucesso', $resources);
    // }

    // public function update(StoreResourceRequest $request, string $id)
    // {
    //     $resource = Recursos::select('*')->where('id', $id)->first();
    //     if (!$resource)
    //         return $this->error(404, 'Recurso não encontrado');

    //     $data = $request->validated();

    //     $result = Recursos::where('id', $id)->update($data);

    //     // $resource['nome'] = $data['nome']; // Não será editável para não quebrar as fórmulas
    //     $resource['preco_euro'] = $data['preco_euro'];
    //     $resource['preco_dolar'] = $data['preco_dolar'];
    //     $resource['preco_real'] = $data['preco_real'];

    //     if (!$result)
    //         return $this->error(500, 'Erro interno ao atualizar taxa');

    //     return $this->response(200, 'Taxa atualizada com sucesso', $resource);
    // }
}
