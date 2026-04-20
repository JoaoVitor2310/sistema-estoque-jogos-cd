<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Vip;
use App\Models\VipList;
use App\Services\VipListExecutionService;
use App\Traits\HttpResponses;
use App\UseCases\Vips\ExecuteVipListUseCase;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VipController extends Controller
{
    use HttpResponses;

    public function __construct(
        private readonly ExecuteVipListUseCase $executeVipListUseCase,
        private readonly VipListExecutionService $vipListExecutionService,
    ) {}

    public function index()
    {
        $vips = Vip::with('list')->get();
        return Inertia::render('Vips', [
            'vips' => $vips,
        ]);
    }

    public function runVipList(Request $request, Vip $vip)
    {
        $result = $this->executeVipListUseCase->execute($vip);

        if (! $result['success']) {
            if (isset($result['data'])) {
                return $this->error($result['code'], $result['message'], $result['data']);
            }

            return $this->error($result['code'], $result['message']);
        }

        return $this->response(200, $result['message'], $result['data']);
    }

    public function callbackVipList(Request $request, VipList $vipList)
    {
        $data = $request->all();

        $this->vipListExecutionService->applyCallback($vipList, $data);

        return $this->response(200, 'Lista executada com sucesso', $data);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'id_steam'    => 'nullable|string|max:255',
        ]);

        $vip = Vip::create($validated);

        return $this->response(201, 'VIP criado com sucesso', $vip);
    }

    public function update(Request $request, string $id)
    {
        $vip = Vip::findOrFail($id);

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'id_steam'    => 'nullable|string|max:255',
        ]);

        $vip->update($validated);

        return $this->response(200, 'VIP atualizado com sucesso', $vip);
    }

    public function destroy(string $id)
    {
        $vip = Vip::findOrFail($id);
        $vip->delete();

        return response()->json([
            'message' => 'VIP excluído com sucesso.',
        ]);
    }
}
