<?php

namespace App\Http\Controllers\Keys;

use App\Domain\Enums\ClaimType;
use App\Domain\Enums\KeyFormat;
use App\Domain\Enums\SellPlatform;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGameRequest;
use App\Http\Requests\StoreGameRequestArray;
use App\Http\Resources\KeyResource;
use App\Models\Key;
use App\Traits\HttpResponses;
use App\UseCases\Keys\RegisterKeyUseCase;
use App\UseCases\Keys\UpdateKeyUseCase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * CRUD de keys + renderização da página inicial.
 * Responsabilidade: HTTP only — recebe request, delega ao UseCase, retorna response.
 */
class KeyController extends Controller
{
    use HttpResponses;

    /**
     * Campos visíveis para visitantes não autenticados na página /keys.
     * Qualquer outro campo (key_code, gamivo_id, supplier_url, etc.) é ocultado.
     */
    private const GUEST_VISIBLE_FIELDS = [
        'identified_platform',
        'game_name',
        'region',
        'market_price',
        'individual_cost',
        'min_api',
        'max_api',
        'purchase_profit',
        'purchase_profit_percent',
        'acquired_at',
        'sold_at',
        'expires_at',
    ];

    public function __construct(
        private readonly RegisterKeyUseCase $registerKeyUseCase,
        private readonly UpdateKeyUseCase $updateKeyUseCase,
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
            : collect($games->items())->map(fn ($k) => $k->only(self::GUEST_VISIBLE_FIELDS))->all();

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
            : $games->through(fn ($k) => $k->only(self::GUEST_VISIBLE_FIELDS));

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
     * Busca paginada com filtros dinâmicos.
     */
    public function search(Request $request)
    {
        $filters = $request->except('page');

        $query = Key::when(Gate::allows('can-edit'), fn ($q) => $q->with(['supplier']));

        foreach ($filters as $key => $value) {
            if (! $value) {
                continue;
            }

            if (is_array($value)) {
                $query->whereIn($key, $value);

                continue;
            }

            if (is_string($value)) {
                // Filtros de range de data: sufixo _from → >= / sufixo _to → <=
                // Ex: acquired_at_from, listed_at_to, sold_at_from, expires_at_to…
                if (str_ends_with($key, '_from')) {
                    $column = substr($key, 0, -5); // remove "_from"
                    $query->where($column, '>=', $value);

                    continue;
                }

                if (str_ends_with($key, '_to')) {
                    $column = substr($key, 0, -3); // remove "_to"
                    $query->where($column, '<=', $value);

                    continue;
                }

                // Campos de data: filtro por presença/ausência (sim/nao)
                if (in_array($key, ['listed_at', 'sold_at', 'expires_at'])) {
                    match ($value) {
                        'sim' => $query->whereNotNull($key),
                        'nao' => $query->whereNull($key),
                        default => $query->where($key, 'ILIKE', "%{$value}%"),
                    };

                    continue;
                }

                // Filtro de presença de observação
                if ($key === 'notes_filled') {
                    match ($value) {
                        'sim' => $query->whereNotNull('notes')->where('notes', '!=', ''),
                        'nao' => $query->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', '')),
                        default => null,
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
        $canEdit = Gate::allows('can-edit');
        $games = $query->orderBy('id', 'desc')->paginate($limit);

        $displayGames = $canEdit
            ? $games
            : $games->through(fn ($k) => $k->only(self::GUEST_VISIBLE_FIELDS));

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
        $game = Key::find($id);

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
            $item = Key::find($game['id']);

            if (! $item) {
                return $this->error(404, 'Jogo não encontrado');
            }

            $item->delete();
        }

        return $this->response(200, 'Jogos deletados com sucesso', $games);
    }
}
