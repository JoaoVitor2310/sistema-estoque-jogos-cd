<?php

namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFeeRequest;
use App\Http\Requests\StoreRangeG2ARequest;
use App\Http\Requests\UpdateFeeRequest;
use App\Models\Ranges_taxa_G2A;
use App\Traits\HttpResponses;
use App\Models\Taxas;

use Illuminate\Http\Request;
use Inertia\Inertia;
class TaxaController extends Controller
{
    use HttpResponses;
    /**
     * Display a listing of the resource.
     */
    public function showMarketPlaceFees(Request $request)
    {
        $limit = $request->query('limit', 100);  // Valor padrão de 100 para buscar tudo
        $offset = $request->query('offset', 0);  // Valor padrão de 0 para procurar desde o primeiro

        // Busca os registros utilizando limit e offset
        $taxas = Taxas::orderBy('id', 'asc')->limit($limit)->offset($offset)->get();

        is_object($taxas) ? $taxas = $taxas->toArray() : $taxas; // Garante que sempre será um array, mesmo que tenha só um elemento

        //  return $this->response(200, 'Taxas encontrados com sucesso.', $taxas);

        return Inertia::render('Taxas', [
            'taxas' => $taxas,
        ]);
    }

    public function showRangesG2A(Request $request)
    {
        $limit = $request->query('limit', 100);  // Valor padrão de 100 para buscar tudo
        $offset = $request->query('offset', 0);  // Valor padrão de 0 para procurar desde o primeiro

        // Busca os registros utilizando limit e offset
        $taxas = Ranges_taxa_G2A::orderBy('id', 'asc')->limit($limit)->offset($offset)->get();

        is_object($taxas) ? $taxas = $taxas->toArray() : $taxas; // Garante que sempre será um array, mesmo que tenha só um elemento

        //  return $this->response(200, 'Taxas encontrados com sucesso.', $taxas);

        return Inertia::render('RangesTaxaG2A', [
            'taxas' => $taxas,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreFeeRequest $request)
    {
        $data = $request->validated();

        try {
            $created = Taxas::create($data);
            if ($created) {
                return $this->response(201, 'Taxa cadastrada com sucesso', $created);
            }

            return $this->error(400, 'Something went wrong!');
        } catch (\Exception $e) {
            \Log::error($e);

            // Return a JSON response with the error message
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
        $fee = Taxas::select('*')->where('id', $id)->first();
        if (!$fee)
            return $this->error(404, 'Taxa não encontrada');

        $data = $request->validated();

        $result = Taxas::where('id', $id)->update($data);

        if (!$result)
            return $this->error(500, 'Erro interno ao atualizar taxa');

        $fee['preco'] = $data['preco'];

        return $this->response(200, 'Taxa atualizada com sucesso', $fee);

        // return to_route('fees'); // Inertia
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $taxa = Taxas::select('*')->where('id', $id)->first();
        if (!$taxa)
            return $this->error(404, 'Taxa não encontrada');


        $result = Taxas::where('id', $id)->delete();
        if (!$result)
            return $this->error(500, 'Erro interno ao deletar taxa');

        return $this->response(200, 'Taxa deletada com sucesso', $taxa);
    }

    public function destroyArray(Request $request)
    {
        $taxas = $request->input('taxas');
        if (!$taxas)
            return $this->error(404, 'Taxas não enviadas', ['taxas' => 'Taxas não enviadas']);
        // return $this->response(200, 'a', $taxas);
        foreach ($taxas as $taxa) {

            $item = Taxas::select('*')->where('id', $taxa['id'])->first();
            if (!$item)
                return $this->error(404, 'Taxa não encontrada');

            $result = Taxas::where('id', $taxa['id'])->delete();
            if (!$result)
                return $this->error(500, 'Erro interno ao deletar taxa');
        }

        return $this->response(200, 'Taxas deletadas com sucesso', $taxas);
    }

    public function storeRangeG2A(StoreRangeG2ARequest $request)
    {
        $data = $request->validated();

        try {
            $created = Ranges_taxa_G2A::create($data);
            if ($created) {
                return $this->response(201, 'Taxa cadastrada com sucesso', $created);
            }

            return $this->error(400, 'Something went wrong!');
        } catch (\Exception $e) {
            \Log::error($e);

            // Return a JSON response with the error message
            return $this->error(500, 'Erro interno ao cadastrar taxa nova.', [$e->getMessage()]);
        }
    }

    public function destroyRangeG2A(string $id)
    {
        $taxa = Ranges_taxa_G2A::select('*')->where('id', $id)->first();
        if (!$taxa)
            return $this->error(404, 'Taxa não encontrada');


        $result = Ranges_taxa_G2A::where('id', $id)->delete();
        if (!$result)
            return $this->error(500, 'Erro interno ao deletar taxa');

        return $this->response(200, 'Taxa deletada com sucesso', $taxa);
    }

    public function destroyArrayG2A(Request $request)
    {
        $taxas = $request->input('taxas');
        if (!$taxas)
            return $this->error(404, 'Taxas não enviadas', ['taxas' => 'Taxas não enviadas']);
        // return $this->response(200, 'a', $taxas);
        foreach ($taxas as $taxa) {

            $item = Ranges_taxa_G2A::select('*')->where('id', $taxa['id'])->first();
            if (!$item)
                return $this->error(404, 'Taxa não encontrada');

            $result = Ranges_taxa_G2A::where('id', $taxa['id'])->delete();
            if (!$result)
                return $this->error(500, 'Erro interno ao deletar taxa');
        }

        return $this->response(200, 'Taxas deletadas com sucesso', $taxas);
    }

    public function updateRangeG2A(StoreRangeG2ARequest $request, string $id)
    {
        $fee = Ranges_taxa_G2A::select('*')->where('id', $id)->first();
        if (!$fee)
            return $this->error(404, 'Taxa não encontrada');

        $data = $request->validated();

        $result = Ranges_taxa_G2A::where('id', $id)->update($data);

        $fee['minimo'] = $data['minimo'];
        $fee['maximo'] = $data['maximo'];
        $fee['taxa'] = $data['taxa'];

        if (!$result)
            return $this->error(500, 'Erro interno ao atualizar taxa');

        return $this->response(200, 'Taxa atualizada com sucesso', $fee);

        // return to_route('fees'); // Inertia
    }
}