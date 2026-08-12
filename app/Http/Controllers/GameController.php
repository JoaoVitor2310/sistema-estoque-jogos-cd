<?php

namespace App\Http\Controllers;

use App\Http\Requests\GameRequest;
use App\Http\Requests\GameRequestArray;
use App\Http\Requests\IndexGamesRequest;
use App\Models\Game;
use App\Services\Games\GameRepository;
use App\Traits\HttpResponses;
use App\UseCases\Games\RegisterGamesUseCase;
use App\UseCases\Games\UpdateGameUseCase;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class GameController extends Controller
{
    use HttpResponses;

    public function __construct(
        private readonly GameRepository $gameRepository,
        private readonly RegisterGamesUseCase $registerGamesUseCase,
        private readonly UpdateGameUseCase $updateGameUseCase,
    ) {}

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
            'bundles',
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

    /**
     * Store a newly created resource in storage.
     */
    public function store(GameRequestArray $request)
    {
        try {
            $result = $this->registerGamesUseCase->execute($request->validated()['games']);
        } catch (Exception $e) {
            Log::error('Erro ao cadastrar novo jogo', [$e->getMessage()]);

            return $this->error(500, 'Erro interno ao cadastrar novo jogo', [$e->getMessage()]);
        }

        if (! empty($result['skipped'])) {
            return $this->response(201, 'Jogos cadastrados com sucesso, mas tem pelo menos um com o nome repetido:
            '.implode(', ', $result['skipped']), $result['created']);
        }

        return $this->response(201, 'Jogos cadastrados com sucesso', $result['created']);
    }

    public function search(IndexGamesRequest $request)
    {
        $games = $this->gameRepository->paginate($request->filters(), $request->perPage());

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
     * Update the specified resource in storage.
     */
    public function update(GameRequest $request, Game $game)
    {
        try {
            $game = $this->updateGameUseCase->execute($game, $request->validated());
        } catch (Exception $e) {
            Log::error('Erro ao atualizar jogo', [$e->getMessage()]);

            return $this->error(500, 'Erro interno ao atualizar jogo', [$e->getMessage()]);
        }

        return $this->response(200, 'Jogo atualizado com sucesso', $game);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Game $game)
    {
        $game->delete();

        return $this->response(200, 'Jogo deletado com sucesso', $game);
    }

    public function destroyArray(Request $request)
    {
        try {
            DB::beginTransaction();
            $games = $request->input('games');
            if (! $games) {
                return $this->error(404, 'Jogos não enviados', ['games' => 'Jogos não enviados']);
            }
            // return $this->response(200, 'a', $jogos);
            foreach ($games as $game) {

                $item = Game::select('*')->where('id', $game['id'])->first();
                if (! $item) {
                    return $this->error(404, 'Jogo não encontrado');
                }

                $result = Game::where('id', $game['id'])->delete();
                if (! $result) {
                    return $this->error(500, 'Erro interno ao deletar jogo');
                }
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
