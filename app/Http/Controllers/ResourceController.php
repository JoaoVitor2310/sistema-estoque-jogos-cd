<?php

namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResourceRequest;
use App\Traits\HttpResponses;
use Illuminate\Http\Request;
use App\Models\Recursos;
use Inertia\Inertia;

class ResourceController extends Controller
{
    use HttpResponses;

    public function show(Request $request)
    {
        $resources = Recursos::orderBy('id', 'asc')->get();

        is_object($resources) ? $resources = $resources->toArray() : $resources; // Garante que sempre será um array, mesmo que tenha só um elemento

        //  return $this->response(200, 'resources encontrados com sucesso.', $resources);

        return Inertia::render('Resources', [
            'resources' => $resources,
        ]);
    }

    public function store(StoreResourceRequest $request)
    {
        $data = $request->validated();

        try {
            $created = Recursos::create($data);
            if ($created) {
                return $this->response(201, 'Recurso cadastrado com sucesso', $created);
            }

            return $this->error(400, 'Something went wrong!');
        } catch (\Exception $e) {
            \Log::error($e);

            // Return a JSON response with the error message
            return $this->error(500, 'Erro interno ao cadastrar recurso novo.', [$e->getMessage()]);
        }
    }

    public function destroy(string $id)
    {
        $resource = Recursos::select('*')->where('id', $id)->first();
        if (!$resource)
            return $this->error(404, 'Recurso não encontrado');


        $result = Recursos::where('id', $id)->delete();
        if (!$result)
            return $this->error(500, 'Erro interno ao deletar recurso');

        return $this->response(200, 'Recurso deletado com sucesso', $resource);
    }

    public function destroyArray(Request $request)
    {
        $resources = $request->input('resources');
        if (!$resources)
            return $this->error(404, 'Recursos não enviadas', ['resources' => 'Recursos não enviadas']);
        
        foreach ($resources as $resource) {

            $item = Recursos::select('*')->where('id', $resource['id'])->first();
            if (!$item)
                return $this->error(404, 'Taxa não encontrada');

            $result = Recursos::where('id', $resource['id'])->delete();
            if (!$result)
                return $this->error(500, 'Erro interno ao deletar taxa');
        }

        return $this->response(200, 'Recursos deletadas com sucesso', $resources);
    }

    public function update(StoreResourceRequest $request, string $id)
    {
        $resource = Recursos::select('*')->where('id', $id)->first();
        if (!$resource)
            return $this->error(404, 'Recurso não encontrado');

        $data = $request->validated();

        $result = Recursos::where('id', $id)->update($data);

        // $resource['nome'] = $data['nome']; // Não será editável para não quebrar as fórmulas
        $resource['preco_euro'] = $data['preco_euro'];
        $resource['preco_dolar'] = $data['preco_dolar'];
        $resource['preco_real'] = $data['preco_real'];

        if (!$result)
            return $this->error(500, 'Erro interno ao atualizar taxa');

        return $this->response(200, 'Taxa atualizada com sucesso', $resource);
    }
}