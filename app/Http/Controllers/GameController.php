<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\GameRequest;
use App\Http\Requests\GameRequestArray;
use App\Models\Game;
use App\Models\Venda_chave_troca;
use App\Services\GameService;
use App\Traits\HttpResponses;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class GameController extends Controller
{
    use HttpResponses;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $limit = $request->query('limit', 100);  // Valor padrão de 100
        $games = Game::with('bundles')->orderBy('id', 'desc')->paginate($limit);

        $totalGames = $games->total();  // O paginate já retorna o total de registros

        // Se for a primeira requisição (renderizar a página com Inertia.js)
        return Inertia::render('Games', [
            // 'games' => $games,
            'games' => $games->items(), // Retorna apenas os itens da página atual
            'totalGames' => $totalGames,
            'pagination' => [
                'current_page' => $games->currentPage(),
                'last_page' => $games->lastPage(),
                'per_page' => $games->perPage(),
            ],
        ]);
    }

    public function paginated(Request $request) // Não renderiza a tela inicial
    {
        $limit = $request->query('limit', 100);  // Valor padrão de 100

        $games = Game::with([
            'bundles'
            // ])->orderBy('id', 'desc')->limit($limit)->offset($offset)->get();
        ])->orderBy('id', 'desc')->paginate($limit);

        $totalGames = $games->total();  // O paginate já retorna o total de registros

        return $this->response(200, 'Página de jogos atualizada com sucesso.', [
            'games' => $games,
            // 'games' => $games->items(), // Retorna apenas os itens da página atual
            'totalGames' => $totalGames,
            'pagination' => [
                'current_page' => $games->currentPage(),
                'last_page' => $games->lastPage(),
                'per_page' => $games->perPage(),
            ],
        ]);
    }

    public function searchPopularity(Request $request)
    {
        $games = Game::select('*')->whereNotNull('id_steamcharts')->get()->toArray();
        return $this->response(200, 'Jogos encontrados com sucesso', $games);
    }

    public function updatePopularity(Request $request)
    {
        $games = $request->input('games');

        foreach ($games as $game) {
            $game = Game::where('id', $game['id'])->update(['popularity' => $game['popularity']]);
        }

        return $this->response(200, 'Jogos atualizados com sucesso', $games);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GameRequestArray $request)
    {
        try {
            DB::beginTransaction();
            $data = $request->validated();

            $repeatedGames = [];
            $fullGames = [];
            foreach ($data['games'] as $game) {

                $repeatedGame = Game::where('name', $game['name'])->where('region', $game['region'])->first();

                if ($repeatedGame) {
                    $repeatedGames[] = $game['name'];
                    continue;
                }

                if (empty($game['id_gamivo'])) {
                    $gameService = new GameService();
                    $id_gamivo = $gameService->fillIdGamivo($game['name'], $game['region']);
                    if ($id_gamivo) $game['id_gamivo'] = $id_gamivo;
                }

                $created = Game::create($game);
                if ($created) {
                    $fullGame = Game::select('*')->where('id', $created->id)->with([
                        'bundles'
                    ])->first();

                    $fullGames[] = $fullGame;
                } else {
                    return $this->error(400, 'Algo deu errado!');
                }
            }


            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erro ao cadastrar novo jogo', [$e->getMessage()]);
            return $this->error(500, 'Erro interno ao cadastrar novo jogo', [$e->getMessage()]);
        }

        if (!empty($repeatedGames)) {
            return $this->response(201, 'Jogos cadastrados com sucesso, mas tem pelo menos um com o nome repetido:
            ' . implode(', ', $repeatedGames), $fullGames);
        }
        return $this->response(201, 'Jogos cadastrados com sucesso', $fullGames);
    }

    public function search(Request $request)
    {
        $filters = $request->except('page'); // Filtra todos os campos, exceto 'page'

        // Iniciando a consulta
        $query = Game::with([
            'bundles'
        ]);

        // return $this->response(200, 'DEBUG.', $filters);
        foreach ($filters as $key => $value) {
            if ($value) {
                if (is_array($value)) {
                    $query->whereIn($key, $value);
                } else if (is_string($value)) {
                    // Tratamento especial para o filtro dataVenda
                    if ($key === 'release_date') {
                        if ($value === 'sim') {
                            $query->whereNotNull($key);
                        } else if ($value === 'nao') {
                            $query->whereNull($key);
                        } else {
                            $query->where($key, 'ILIKE', "%" . $value . "%");
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
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GameRequest $request, string $id)
    {
        try {
            DB::beginTransaction();
            $game = Game::with('bundles')->find($id);

            if (!$game)
                return $this->error(404, 'Jogo não encontrado');

            $updatedGame = $request->validated();

            if ($updatedGame['id_gamivo'] == '') {
                $gameService = new GameService();
                $id_gamivo = $gameService->fillIdGamivo($updatedGame['name'], $updatedGame['region']);
                if ($id_gamivo) $updatedGame['id_gamivo'] = $id_gamivo;
            }

            $result = Game::where('id', $id)->update($updatedGame); // Atualiza

            if (!$result)
                return $this->error(500, 'Erro interno ao atualizar jogo');

            $game = Game::select('*')->where('id', $id)->with([
                'bundles'
            ])->first();

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erro ao atualizar jogo', [$e->getMessage()]);
            return $this->error(500, 'Erro interno ao atualizar jogo', [$e->getMessage()]);
        }

        return $this->response(200, 'Jogo atualizado com sucesso', $game);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $game = Game::select('*')->where('id', $id)->first();
        if (!$game)
            return $this->error(404, 'Jogo não encontrado');


        $result = Game::where('id', $id)->delete();
        if (!$result)
            return $this->error(500, 'Erro interno ao deletar jogo');

        return $this->response(200, 'Jogo deletado com sucesso', $game);
    }

    public function destroyArray(Request $request)
    {
        try {
            DB::beginTransaction();
            $games = $request->input('games');
            if (!$games)
                return $this->error(404, 'Jogos não enviados', ['games' => 'Jogos não enviados']);
            // return $this->response(200, 'a', $jogos);
            foreach ($games as $game) {

                $item = Game::select('*')->where('id', $game['id'])->first();
                if (!$item)
                    return $this->error(404, 'Jogo não encontrado');

                $result = Game::where('id', $game['id'])->delete();
                if (!$result)
                    return $this->error(500, 'Erro interno ao deletar jogo');
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erro ao deletar jogos', [$e->getMessage()]);
            return $this->error(500, 'Erro interno ao deletar jogos', [$e->getMessage()]);
        }

        return $this->response(200, 'Jogos deletados com sucesso', $games);
    }
}
