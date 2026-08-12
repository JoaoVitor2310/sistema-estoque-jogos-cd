<?php

namespace App\UseCases\Games;

use App\Domain\Games\GameNameNormalizer;
use App\Models\Game;
use App\Services\Games\GameService;

/**
 * Atualiza um jogo, mantendo derivados coerentes com o que foi editado.
 *
 * Dois campos não são digitados e sim derivados: `normalized_name`, que
 * acompanha o nome, e `gamivo_id`, que é procurado no estoque quando o
 * formulário o deixa vazio.
 */
class UpdateGameUseCase
{
    public function __construct(
        private readonly GameService $gameService,
    ) {}

    /**
     * @param  array<string, mixed>  $data  payload já validado pelo GameRequest
     */
    public function execute(Game $game, array $data): Game
    {
        if (empty($data['gamivo_id'])) {
            $idGamivo = $this->gameService->getIdGamivo(
                $data['name'] ?? $game->name,
                $data['region'] ?? $game->region,
            );

            if ($idGamivo) {
                $data['gamivo_id'] = $idGamivo;
            }
        }

        // Só recalcula quando o nome veio na edição: `normalized_name` é o que
        // casa o jogo com o price_researcher, e regravá-lo a partir de um nome
        // ausente o zeraria.
        if (isset($data['name'])) {
            $data['normalized_name'] = GameNameNormalizer::normalize($data['name']);
        }

        $game->fill($data);
        $game->save();

        return $game->load('bundles');
    }
}
