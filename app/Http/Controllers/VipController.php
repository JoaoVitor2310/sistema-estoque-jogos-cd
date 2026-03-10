<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Vip;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VipController extends Controller
{
    public function index()
    {
        $vips = Vip::all();
        return Inertia::render('Vips/Index', [
            'vips' => $vips,
        ]);
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
