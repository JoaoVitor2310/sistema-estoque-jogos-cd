<?php

namespace App\Http\Controllers\Keys;

use App\Domain\Enums\ClaimType;
use App\Domain\Enums\KeyFormat;
use App\Domain\Enums\SellPlatform;
use App\Domain\Keys\GuestKeyVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexKeysRequest;
use App\Http\Requests\StoreGameRequest;
use App\Http\Resources\KeyResource;
use App\Models\Key;
use App\Services\Keys\KeyRepository;
use App\Traits\HttpResponses;
use App\UseCases\Keys\UpdateKeyUseCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Leitura, edição e remoção de keys + renderização da página inicial.
 * Responsabilidade: HTTP only — recebe request, delega ao UseCase, retorna response.
 *
 * A criação de keys não vive aqui: o único caminho de entrada é a importação
 * por trade (`POST /trades/{trade}/import` → `TradeController::importKeys`).
 */
class KeyController extends Controller
{
    use HttpResponses;

    public function __construct(
        private readonly UpdateKeyUseCase $updateKeyUseCase,
        private readonly KeyRepository $keyRepository,
    ) {}

    /**
     * Renderiza a página de keys via Inertia (primeira carga).
     */
    public function show(Request $request)
    {
        $limit = $request->query('limit', 100);
        $canEdit = Gate::allows('can-edit');

        $games = Key::when($canEdit, fn ($q) => $q->with(['supplier']))
            ->orderBy('id', 'desc')
            ->paginate($limit);

        $items = $canEdit
            ? $games->items()
            : collect($games->items())->map(fn ($k) => $k->only(GuestKeyVisibility::FIELDS))->all();

        return Inertia::render('Keys', [
            'games' => $items,
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
        $canEdit = Gate::allows('can-edit');

        $games = Key::when($canEdit, fn ($q) => $q->with(['supplier']))
            ->orderBy('id', 'desc')
            ->paginate($limit);

        $displayGames = $canEdit
            ? $games
            : $games->through(fn ($k) => $k->only(GuestKeyVisibility::FIELDS));

        return $this->response(200, 'Página de jogos atualizada com sucesso.', [
            'games' => $displayGames,
            'totalGames' => $games->total(),
            'pagination' => [
                'current_page' => $games->currentPage(),
                'last_page' => $games->lastPage(),
                'per_page' => $games->perPage(),
            ],
        ]);
    }

    /**
     * Busca paginada.
     *
     * Os filtros aceitos e quem pode usar cada um são decididos pelo
     * IndexKeysRequest; a montagem da query vive no KeyRepository.
     */
    public function search(IndexKeysRequest $request)
    {
        $canEdit = Gate::allows('can-edit');

        $games = $this->keyRepository->paginate(
            $request->filters(),
            $request->perPage(),
            withSupplier: $canEdit,
        );

        $displayGames = $canEdit
            ? $games
            : $games->through(fn ($k) => $k->only(GuestKeyVisibility::FIELDS));

        return $this->response(200, 'Pesquisa realizada com sucesso.', [
            'games' => $displayGames,
            'totalGames' => $games->total(),
            'pagination' => [
                'current_page' => $games->currentPage(),
                'last_page' => $games->lastPage(),
                'per_page' => $games->perPage(),
            ],
        ]);
    }

    /**
     * Atualiza uma key existente.
     */
    public function update(StoreGameRequest $request, Key $key)
    {
        // Devolve todas as keys afetadas — o lote inteiro quando o market_price muda.
        $result = $this->updateKeyUseCase->execute($key, $request->validated());

        return $this->response(200, $result['message'], KeyResource::collection($result['keys'])->resolve());
    }

    /**
     * Remove uma key.
     */
    public function destroy(Key $key)
    {
        $key->delete();

        return $this->response(200, 'Key deletada com sucesso', new KeyResource($key));
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
            $item = Key::find($game['id']);

            if (! $item) {
                return $this->error(404, 'Jogo não encontrado');
            }

            $item->delete();
        }

        return $this->response(200, 'Jogos deletados com sucesso', $games);
    }
}
