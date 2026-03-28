<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Vip;
use App\Models\VipList;
use App\Services\VipListExecutionService;
use App\Traits\HttpResponses;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VipController extends Controller
{
    use HttpResponses;

    public function __construct(
        private readonly VipListExecutionService $vipListExecutionService
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
        $result = $this->vipListExecutionService->queueRunForVip($vip);

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
            'first_link'  => 'nullable|string|max:255',
            'second_link' => 'nullable|string|max:255',
            'third_link'  => 'nullable|string|max:255',
            'steam_link'  => 'nullable|string|max:255',
        ]);

        $vip = Vip::create($validated);

        return response()->json([
            'message' => 'VIP criado com sucesso.',
            'data'    => $vip,
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $vip = Vip::findOrFail($id);

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'first_link'  => 'nullable|string|max:255',
            'second_link' => 'nullable|string|max:255',
            'third_link'  => 'nullable|string|max:255',
            'steam_link'  => 'nullable|string|max:255',
        ]);

        $vip->update($validated);

        return response()->json([
            'message' => 'VIP atualizado com sucesso.',
            'data'    => $vip,
        ]);
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
