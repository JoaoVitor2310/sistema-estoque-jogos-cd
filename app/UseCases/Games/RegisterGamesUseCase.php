<?php

namespace App\UseCases\Games;

use App\Domain\Games\GameNameNormalizer;
use App\Models\Game;
use App\Services\Games\GameService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cadastra um lote de jogos.
 *
 * Duplicata não é erro: o par (nome, região) já existente é **pulado** e
 * devolvido à parte, para que a tela avise sem abortar o lote inteiro — quem
 * cola uma lista de jogos costuma repetir um ou outro sem perceber.
 *
 * O lote roda numa transação: metade dos jogos cadastrados deixaria o operador
 * sem saber o que reenviar.
 */
class RegisterGamesUseCase
{
    public function __construct(
        private readonly GameService $gameService,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $games  payload já validado pelo GameRequestArray
     * @return array{created: Collection<int, Game>, skipped: string[]}
     */
    public function execute(array $games): array
    {
        return DB::transaction(function () use ($games) {
            /** @var Collection<int, Game> $created */
            $created = new Collection;
            $skipped = [];

            foreach ($games as $game) {
                $alreadyRegistered = Game::where('name', $game['name'])
                    ->where('region', $game['region'] ?? null)
                    ->exists();

                if ($alreadyRegistered) {
                    $skipped[] = $game['name'];

                    continue;
                }

                $created->push($this->create($game));
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /**
     * @param  array<string, mixed>  $game
     */
    private function create(array $game): Game
    {
        // O gamivo_id é o que liga o jogo à oferta no marketplace. Quando não
        // vem no formulário, aproveita o que já foi descoberto em keys/games do
        // mesmo título antes de deixar o campo vazio.
        if (empty($game['gamivo_id'])) {
            $idGamivo = $this->gameService->getIdGamivo($game['name'], $game['region'] ?? null);

            if ($idGamivo) {
                $game['gamivo_id'] = $idGamivo;
            }
        }

        $game['normalized_name'] = GameNameNormalizer::normalize($game['name']);

        return Game::create($game)->load('bundles');
    }
}
