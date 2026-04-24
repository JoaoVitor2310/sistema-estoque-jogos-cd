<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFeeRequest;
use App\Http\Requests\UpdateFeeRequest;
use App\Models\Fee;
use App\Traits\HttpResponses;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FeeController extends Controller
{
    use HttpResponses;

    /**
     * Display a listing of the resource.
     */
    public function showMarketPlaceFees(Request $request)
    {
        $limit = $request->query('limit', 100);
        $offset = $request->query('offset', 0);

        $fees = Fee::orderBy('id', 'asc')->limit($limit)->offset($offset)->get();

        is_object($fees) ? $fees = $fees->toArray() : $fees;

        return Inertia::render('Taxas', [
            'taxas' => $fees,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreFeeRequest $request)
    {
        $data = $request->validated();

        try {
            $created = Fee::create($data);
            if ($created) {
                return $this->response(201, 'Taxa cadastrada com sucesso', $created);
            }

            return $this->error(400, 'Something went wrong!');
        } catch (\Exception $e) {
            \Log::error($e);

            return $this->error(500, 'Erro interno ao cadastrar taxa nova.', [$e->getMessage()]);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateFeeRequest $request, string $id)
    {
        $fee = Fee::select('*')->where('id', $id)->first();
        if (! $fee) {
            return $this->error(404, 'Taxa não encontrada');
        }

        $data = $request->validated();

        $result = Fee::where('id', $id)->update($data);

        if (! $result) {
            return $this->error(500, 'Erro interno ao atualizar taxa');
        }

        $fee['preco'] = $data['preco'];

        return $this->response(200, 'Taxa atualizada com sucesso', $fee);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $fee = Fee::select('*')->where('id', $id)->first();
        if (! $fee) {
            return $this->error(404, 'Taxa não encontrada');
        }

        $result = Fee::where('id', $id)->delete();
        if (! $result) {
            return $this->error(500, 'Erro interno ao deletar taxa');
        }

        return $this->response(200, 'Taxa deletada com sucesso', $fee);
    }

    public function destroyArray(Request $request)
    {
        $fees = $request->input('taxas');
        if (! $fees) {
            return $this->error(404, 'Taxas não enviadas', ['taxas' => 'Taxas não enviadas']);
        }

        foreach ($fees as $fee) {
            $item = Fee::select('*')->where('id', $fee['id'])->first();
            if (! $item) {
                return $this->error(404, 'Taxa não encontrada');
            }

            $result = Fee::where('id', $fee['id'])->delete();
            if (! $result) {
                return $this->error(500, 'Erro interno ao deletar taxa');
            }
        }

        return $this->response(200, 'Taxas deletadas com sucesso', $fees);
    }
}
