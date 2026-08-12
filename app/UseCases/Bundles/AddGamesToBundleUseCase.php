<?php

namespace App\UseCases\Bundles;

use App\Models\Bundle;

/**
 * Vincula jogos a um bundle já existente.
 *
 * Jogo repetido não é erro de banco — a tabela pivot aceitaria a duplicata —
 * mas é erro de operação: significa que a pessoa selecionou algo que já estava
 * lá. Por isso o lote inteiro é recusado quando nenhum jogo é novo, em vez de
 * responder sucesso sobre uma operação que não mudou nada.
 */
class AddGamesToBundleUseCase
{
    /**
     * @param  int[]  $gameIds
     * @return array{added: bool, bundle: Bundle}
     */
    public function execute(Bundle $bundle, array $gameIds): array
    {
        $alreadyLinked = $bundle->games()->whereIn('games.id', $gameIds)->pluck('games.id')->all();

        if (array_diff($gameIds, $alreadyLinked) === []) {
            return ['added' => false, 'bundle' => $bundle];
        }

        $bundle->games()->syncWithoutDetaching($gameIds);
        $bundle->load(['games' => fn ($query) => $query->orderBy('name', 'asc')]);

        return ['added' => true, 'bundle' => $bundle];
    }
}
