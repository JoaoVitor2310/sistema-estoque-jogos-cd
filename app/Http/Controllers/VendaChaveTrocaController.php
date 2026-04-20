<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGameRequest;
use App\Http\Requests\StoreGameRequestArray;
use App\Models\Plataforma;
use App\Models\Tipo_formato;
use App\Models\Tipo_leilao;
use App\Models\Tipo_reclamacao;
use App\Http\Resources\KeyAutoSellResource;
use App\Traits\HttpResponses;
use App\UseCases\Keys\AutoSellUseCase;
use App\UseCases\Keys\ImportKeysFromXlsxUseCase;
use App\UseCases\Keys\RegisterKeyUseCase;
use App\UseCases\Keys\UpdateKeyUseCase;
use App\UseCases\Keys\UpdateSoldOffersUseCase;
use Illuminate\Http\Request;
use App\Models\Venda_chave_troca;
use App\Http\Requests\ImportKeysRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Inertia\Inertia;

class VendaChaveTrocaController extends Controller
{
    use HttpResponses;

    public function __construct(
        protected RegisterKeyUseCase $registerKeyUseCase,
        protected UpdateKeyUseCase $updateKeyUseCase,
        protected AutoSellUseCase $autoSellUseCase,
        protected UpdateSoldOffersUseCase $updateSoldOffersUseCase,
        protected ImportKeysFromXlsxUseCase $importKeysUseCase,
    ) {}


    public function show(Request $request) // Renderiza a tela inicial
    {
        $limit = $request->query('limit', 100);  // Valor padrão de 100

        $games = Venda_chave_troca::with([
            'fornecedor',
            'tipoReclamacao',
            'tipoFormato',
            'leilaoG2A',
            'leilaoGamivo',
            'leilaoKinguin',
            'plataforma'
            // ])->orderBy('id', 'desc')->limit($limit)->offset($offset)->get();
        ])->orderBy('id', 'desc')->paginate($limit);

        // $totalGames = Venda_chave_troca::count();
        $totalGames = $games->total();  // O paginate já retorna o total de registros
        $tiposFormato = Tipo_formato::all();
        $tiposLeilao = Tipo_leilao::all();
        $plataformas = Plataforma::all();
        $tiposReclamacao = Tipo_reclamacao::all();


        // is_object($games) ? $games = $games->toArray() : $games; // Garante que sempre será um array, mesmo que tenha só um elemento


        // Se for a primeira requisição (renderizar a página com Inertia.js)
        return Inertia::render('VendaChaveTroca', [
            // 'games' => $games,
            'games' => $games->items(), // Retorna apenas os itens da página atual
            'totalGames' => $totalGames,
            'tiposFormato' => $tiposFormato,
            'tiposLeilao' => $tiposLeilao,
            'plataformas' => $plataformas,
            'tiposReclamacao' => $tiposReclamacao,
            'pagination' => [
                'current_page' => $games->currentPage(),
                'last_page' => $games->lastPage(),
                'per_page' => $games->perPage(),
            ],
        ]);

        // return $this->response(200, 'Jogos encontrados com sucesso.', $jogos);
    }

    public function paginated(Request $request) // Não renderiza a tela inicial
    {
        $limit = $request->query('limit', 100);  // Valor padrão de 100
        // $offset = $request->query('offset', 0);  // Valor padrão de 0

        $games = Venda_chave_troca::with([
            'fornecedor',
            'tipoReclamacao',
            'tipoFormato',
            'leilaoG2A',
            'leilaoGamivo',
            'leilaoKinguin',
            'plataforma'
            // ])->orderBy('id', 'desc')->limit($limit)->offset($offset)->get();
        ])->orderBy('id', 'desc')->paginate($limit);

        // $totalGames = Venda_chave_troca::count();
        $totalGames = $games->total();  // O paginate já retorna o total de registros
        $tiposFormato = Tipo_formato::all();
        $tiposLeilao = Tipo_leilao::all();
        $plataformas = Plataforma::all();
        $tiposReclamacao = Tipo_reclamacao::all();


        // is_object($games) ? $games = $games->toArray() : $games; // Garante que sempre será um array, mesmo que tenha só um elemento


        return $this->response(200, 'Página de jogos atualizada com sucesso.', [
            'games' => $games,
            // 'games' => $games->items(), // Retorna apenas os itens da página atual
            'totalGames' => $totalGames,
            'tiposFormato' => $tiposFormato,
            'tiposLeilao' => $tiposLeilao,
            'plataformas' => $plataformas,
            'tiposReclamacao' => $tiposReclamacao,
            'pagination' => [
                'current_page' => $games->currentPage(),
                'last_page' => $games->lastPage(),
                'per_page' => $games->perPage(),
            ],
        ]);


        // return $this->response(200, 'Jogos encontrados com sucesso.', $jogos);
    }

    public function search(Request $request)
    {
        $filters = $request->except('page'); // Filtra todos os campos, exceto 'page'

        // Iniciando a consulta
        $query = Venda_chave_troca::with([
            'fornecedor',
            'tipoReclamacao',
            'tipoFormato',
            'leilaoG2A',
            'leilaoGamivo',
            'leilaoKinguin',
            'plataforma'
        ]);

        // return $this->response(200, 'DEBUG.', $filters);
        foreach ($filters as $key => $value) {
            if ($value) {
                if (is_array($value)) {
                    $query->whereIn($key, $value);
                } else if (is_string($value)) {
                    // Tratamento especial para o filtro dataVenda
                    if ($key === 'dataVenda' || $key === 'dataVendida' || $key === 'dataExpiracao') {
                        if ($value === 'sim') {
                            $query->whereNotNull($key);
                        } else if ($value === 'nao') {
                            $query->whereNull($key);
                        } else {
                            $query->where($key, 'ILIKE', "%" . $value . "%");
                        }
                    } else if ($key === 'hasIdGamivo') {
                        if ($value === 'sim') {
                            $query->whereNotNull('idGamivo');
                        } else if ($value === 'nao') {
                            $query->whereNull('idGamivo');
                        }
                    } else {
                        $query->where($key, 'ILIKE', "%" . $value . "%");
                    }
                } else if (is_bool($value) && str_starts_with($key, 'data')) {
                    $query->whereNull($key);
                } else {
                    $query->where($key, $value);
                }
            }
        }

        // Paginação
        $limit = $filters['limit'] ?? 100;
        $games = $query->orderBy('id', 'desc')->paginate($limit);

        return $this->response(200, 'Pesquisa realizada com sucesso.', [
            'games' => $games,
            'totalGames' => $games->total(),
            'pagination' => [
                'current_page' => $games->currentPage(),
                'last_page' => $games->lastPage(),
                'per_page' => $games->perPage(),
            ],
        ]);
    }
    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreGameRequestArray $request)
    {
        $result = $this->registerKeyUseCase->execute($request->validated()['games']);

        return $this->response(201, $result['message'], $result['games']);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreGameRequest $request, string $id)
    {
        try {
            $game = $this->updateKeyUseCase->execute($id, $request->validated());
        } catch (ModelNotFoundException) {
            return $this->error(404, 'Jogo não encontrado');
        }

        if ($game->plataformaIdentificada === 'DESCONHECIDO') {
            return $this->response(200, 'Jogo atualizado, mas a plataforma não foi identificada.', $game);
        }

        return $this->response(200, 'Jogo atualizado com sucesso', $game);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $game = Venda_chave_troca::select('*')->where('id', $id)->first();
        if (!$game)
            return $this->error(404, 'Jogo não encontrado');


        $result = Venda_chave_troca::where('id', $id)->delete();
        if (!$result)
            return $this->error(500, 'Erro interno ao deletar jogo');

        return $this->response(200, 'Jogo deletado com sucesso', $game);
    }

    public function destroyArray(Request $request)
    {
        $games = $request->input('games');
        if (!$games)
            return $this->error(404, 'Jogos não enviados', ['games' => 'Jogos não enviados']);
        // return $this->response(200, 'a', $jogos);
        foreach ($games as $game) {

            $item = Venda_chave_troca::select('*')->where('id', $game['id'])->first();
            if (!$item)
                return $this->error(404, 'Jogo não encontrado');

            $result = Venda_chave_troca::where('id', $game['id'])->delete();
            if (!$result)
                return $this->error(500, 'Erro interno ao deletar jogo');
        }

        return $this->response(200, 'Jogos deletados com sucesso', $games);
    }

    public function autoSell(Request $request)
    {
        try {
            $keys = $this->autoSellUseCase->execute();
        } catch (\Exception $e) {
            return $this->error(500, 'Erro interno ao listar jogos para venda automaticamente', [$e->getMessage()]);
        }

        return $this->response(200, 'Jogos para listar a venda automaticamente encontrados com sucesso', KeyAutoSellResource::collection($keys));
    }

    public function whenToSell(Request $request)
    {
        $gamesToList = Venda_chave_troca::select(['idGamivo', 'minimoParaVenda', 'valorPagoIndividual', 'chaveRecebida', 'nomeJogo', 'region', 'dataAdquirida', 'dataVenda', 'dataVendida', 'dataExpiracao'])->whereNotNull('idGamivo')->whereNotNull('minimoParaVenda')->whereNull('dataVenda')->whereNull('dataVendida')->get();

        is_object($gamesToList) ? $gamesToList = $gamesToList->toArray() : $gamesToList; // Garante que sempre será um array, mesmo que tenha só um elemento

        return $this->response(200, 'Jogos para listar encontrados com sucesso', $gamesToList);
    }

    public function updateSoldOffers(Request $request)
    {
        $notUpdated = $this->updateSoldOffersUseCase->execute($request->all());

        return $this->response(200, 'Jogos atualizados com sucesso', $notUpdated);
    }

    public function searchByIdGamivo(Request $request, string $idGamivo)
    {
        $games = Venda_chave_troca::select(['minApiGamivo', 'maxApiGamivo'])->where('idGamivo', $idGamivo)->whereNull('dataVendida')->whereNotNull('dataVenda')->get()->toArray();
        return $this->response(200, 'Jogos encontrados com sucesso', $games);
    }

    public function insertDataVenda(Request $request)
    {
        $chaveRecebida = $request->input('chaveRecebida');
        $updateMinApiGamivo = $request->input('updateMinApiGamivo', true);

        if (!$chaveRecebida) return $this->error(404, 'Chave não encontrada', ['chaveRecebida' => 'Chave não encontrada']);

        $data = [
            'dataVenda' => now()->toDateString(),
        ];

        if ($updateMinApiGamivo) {
            $data['minApiGamivo'] = 0.01;
        }

        $updated = Venda_chave_troca::where('chaveRecebida', $chaveRecebida)
            ->whereNull('dataVenda')
            ->update($data);

        if ($updated === 0) {
            return $this->error(404, 'Nenhum registro foi atualizado. Verifique se a chave existe ou se já possui dataVenda.');
        }
        return $this->response(200, 'Data posto a venda inserida com sucesso.', []);
    }

    /**
     * Importa jogos de um arquivo Excel
     */
    public function import(ImportKeysRequest $request)
    {
        $result = $this->importKeysUseCase->execute($request->file('file')->getRealPath());

        if (!$result['success']) {
            return $this->error(422, $result['message'], $result['errors']);
        }

        return $this->response(201, $result['message'], $result['data']);
    }

    /**
     * Download do arquivo de exemplo para importação
     */
    public function downloadExample()
    {
        $filePath = public_path('assets/example/import_keys.xlsx');

        if (!file_exists($filePath)) {
            return $this->error(404, 'Arquivo de exemplo não encontrado');
        }

        return response()->download($filePath, 'exemplo-importacao-jogos.xlsx');
    }

}

