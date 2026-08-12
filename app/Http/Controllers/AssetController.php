<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteAssetsRequest;
use App\Http\Requests\StoreAssetRequest;
use App\Models\Asset;
use App\Traits\HttpResponses;
use App\UseCases\Assets\UpdateAssetPricesUseCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class AssetController extends Controller
{
    use HttpResponses;

    public function __construct(
        private readonly UpdateAssetPricesUseCase $updateAssetPricesUseCase,
    ) {}

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

    public function destroy(Asset $asset)
    {
        $asset->delete();

        return $this->response(200, 'Recurso deletado com sucesso', $asset);
    }

    public function destroyArray(DeleteAssetsRequest $request)
    {
        $ids = $request->ids();

        Asset::whereIn('id', $ids)->delete();

        return $this->response(200, 'Recursos deletados com sucesso', $ids);
    }

    public function update(StoreAssetRequest $request, Asset $asset)
    {
        $asset = $this->updateAssetPricesUseCase->execute($asset, $request->validated());

        return $this->response(200, 'Recurso atualizado com sucesso', $asset);
    }
}
