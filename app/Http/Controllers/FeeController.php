<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteFeesRequest;
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
     * Update the specified resource in storage.
     */
    public function update(UpdateFeeRequest $request, Fee $fee)
    {
        $fee->fill($request->validated());
        $fee->save();

        return $this->response(200, 'Taxa atualizada com sucesso', $fee);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Fee $fee)
    {
        $fee->delete();

        return $this->response(200, 'Taxa deletada com sucesso', $fee);
    }

    public function destroyArray(DeleteFeesRequest $request)
    {
        $ids = $request->ids();

        Fee::whereIn('id', $ids)->delete();

        return $this->response(200, 'Taxas deletadas com sucesso', $ids);
    }
}
