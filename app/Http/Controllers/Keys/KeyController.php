<?php

namespace App\Http\Controllers\Keys;

use App\Domain\Enums\ClaimType;
use App\Domain\Enums\KeyFormat;
use App\Domain\Enums\SellPlatform;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGameRequest;
use App\Http\Requests\StoreGameRequestArray;
use App\Http\Resources\KeyResource;
use App\Models\Venda_chave_troca;
use App\Traits\HttpResponses;
use App\UseCases\Keys\RegisterKeyUseCase;
use App\UseCases\Keys\UpdateKeyUseCase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * CRUD de keys + renderização da página inicial.
 * Responsabilidade: HTTP only — recebe request, delega ao UseCase, retorna response.
 */
class KeyController extends Controller
{
    use HttpResponses;

    public function __construct(
        private readonly RegisterKeyUseCase $registerKeyUseCase,
        private readonly UpdateKeyUseCase $updateKeyUseCase,
    ) {}

    /**
     * Renderiza a página VendaChaveTroca via Inertia (primeira carga).
     */
    public function show(Request $request)
    {
        $limit = $request->query('limit', 100);

        $games = Venda_chave_troca::with(['fornecedor'])
            ->orderBy('id', 'desc')
            ->paginate($limit);

        return Inertia::render('VendaChaveTroca', [
            'games' => $games->items(),
            'totalGames' => $games->total(),
            'pagination' => [
                'current_page' => $games->currentPage(),
                'last_page' => $games->lastPage(),
                'per_page' => $games->perPage(),
            ],
            'keyFormats' => array_column(KeyFormat::cases(), 'value'),
            'claimTypes' => array_column(ClaimType::cases(), 'value'),
            'sellPlatforms' => array_column(SellPlatform::cases(), 'value'),
        ]);
    }

    /**
     * Retorna página de keys em JSON (sem renderizar — usado em navegação client-side).
     */
    public function paginated(Request $request)
    {
        $limit = $request->query('limit', 100);

        $games = Venda_chave_troca::with(['fornecedor'])
            ->orderBy('id', 'desc')
            ->paginate($limit);

        return $this->response(200, 'Página de jogos atualizada com sucesso.', [
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
     * Busca paginada com filtros dinâmicos.
     */
    public function search(Request $request)
    {
        $filters = $request->except('page');

        $query = Venda_chave_troca::with(['fornecedor']);

        foreach ($filters as $key => $value) {
            if (! $value) {
                continue;
            }

            if (is_array($value)) {
                $query->whereIn($key, $value);

                continue;
            }

            if (is_string($value)) {
                // Campos de data: filtro por presença/ausência ou valor
                if (in_array($key, ['listed_at', 'sold_at', 'expires_at'])) {
                    match ($value) {
                        'sim' => $query->whereNotNull($key),
                        'nao' => $query->whereNull($key),
                        default => $query->where($key, 'ILIKE', "%{$value}%"),
                    };

                    continue;
                }

                // Filtro especial: existência do gamivo_id
                if ($key === 'hasIdGamivo') {
                    match ($value) {
                        'sim' => $query->whereNotNull('gamivo_id'),
                        'nao' => $query->whereNull('gamivo_id'),
                        default => null,
                    };

                    continue;
                }

                $query->where($key, 'ILIKE', "%{$value}%");

                continue;
            }

            $query->where($key, $value);
        }

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
     * Registra um lote de keys.
     */
    public function store(StoreGameRequestArray $request)
    {
        $result = $this->registerKeyUseCase->execute($request->validated()['games']);

        return $this->response(201, $result['message'], KeyResource::collection(collect($result['games'])));
    }

    /**
     * Atualiza uma key existente.
     */
    public function update(StoreGameRequest $request, string $id)
    {
        try {
            $game = $this->updateKeyUseCase->execute($id, $request->validated());
        } catch (ModelNotFoundException) {
            return $this->error(404, 'Jogo não encontrado');
        }

        $message = $game->identified_platform === 'DESCONHECIDO'
            ? 'Jogo atualizado, mas a plataforma não foi identificada.'
            : 'Jogo atualizado com sucesso';

        return $this->response(200, $message, new KeyResource($game));
    }

    /**
     * Remove uma key.
     */
    public function destroy(string $id)
    {
        $game = Venda_chave_troca::find($id);

        if (! $game) {
            return $this->error(404, 'Jogo não encontrado');
        }

        $game->delete();

        return $this->response(200, 'Jogo deletado com sucesso', new KeyResource($game));
    }

    /**
     * Remove um lote de keys pelos IDs recebidos.
     */
    public function destroyArray(Request $request)
    {
        $games = $request->input('games');

        if (! $games) {
            return $this->error(404, 'Jogos não enviados', ['games' => 'Jogos não enviados']);
        }

        foreach ($games as $game) {
            $item = Venda_chave_troca::find($game['id']);

            if (! $item) {
                return $this->error(404, 'Jogo não encontrado');
            }

            $item->delete();
        }

        return $this->response(200, 'Jogos deletados com sucesso', $games);
    }
}
