<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAssetRequest;
use App\Models\Asset;
use App\Services\AssetService;
use App\Traits\HttpResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class AssetController extends Controller
{
    use HttpResponses;

    private AssetService $assetService;

    public function __construct()
    {
        $this->assetService = new AssetService;
    }

    public function show(Request $request)
    {
        $assets = Asset::orderBy('id', 'asc')->get();

        is_object($assets) ? $assets = $assets->toArray() : $assets;

        return Inertia::render('Assets', [
            'assets' => $assets,
        ]);
    }

    public function store(StoreAssetRequest $request)
    {
        $data = $request->validated();

        try {
            $created = Asset::create($data);
            if ($created) {
                return $this->response(201, 'Recurso cadastrado com sucesso', $created);
            }

            return $this->error(400, 'Something went wrong!');
        } catch (\Exception $e) {
            Log::error($e);

            return $this->error(500, 'Erro interno ao cadastrar recurso novo.', [$e->getMessage()]);
        }
    }

    public function destroy(string $id)
    {
        $asset = Asset::select('*')->where('id', $id)->first();
        if (! $asset) {
            return $this->error(404, 'Recurso não encontrado');
        }

        $result = Asset::where('id', $id)->delete();
        if (! $result) {
            return $this->error(500, 'Erro interno ao deletar recurso');
        }

        return $this->response(200, 'Recurso deletado com sucesso', $asset);
    }

    public function destroyArray(Request $request)
    {
        $assets = $request->input('assets');
        if (! $assets) {
            return $this->error(404, 'Recursos não enviados', ['assets' => 'Recursos não enviados']);
        }

        foreach ($assets as $asset) {
            $item = Asset::select('*')->where('id', $asset['id'])->first();
            if (! $item) {
                return $this->error(404, 'Recurso não encontrado');
            }

            $result = Asset::where('id', $asset['id'])->delete();
            if (! $result) {
                return $this->error(500, 'Erro interno ao deletar recurso');
            }
        }

        return $this->response(200, 'Recursos deletados com sucesso', $assets);
    }

    public function update(StoreAssetRequest $request, string $id)
    {
        $asset = Asset::select('*')->where('id', $id)->first();
        if (! $asset) {
            return $this->error(404, 'Recurso não encontrado');
        }

        $data = $request->validated();
        $data = $this->assetService->getAssetsCurrency($data);

        $result = $asset->update($data);

        $asset['price_euro'] = $data['price_euro'];
        $asset['price_dollar'] = $data['price_dollar'];
        $asset['price_brl'] = $data['price_brl'];

        if (! $result) {
            return $this->error(500, 'Erro interno ao atualizar recurso');
        }

        return $this->response(200, 'Recurso atualizado com sucesso', $asset);
    }
}
