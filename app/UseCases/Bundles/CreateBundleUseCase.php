<?php

namespace App\UseCases\Bundles;

use App\Models\Bundle;
use Illuminate\Support\Facades\DB;

/**
 * Cria um bundle e vincula os jogos que vieram junto.
 *
 * O vínculo é o que dá sentido ao bundle: um registro sem jogos não exclui key
 * nenhuma da venda (ver KeyEligibility::BUNDLE_EXCLUSION_DAYS). Por isso a
 * criação e o attach acontecem na mesma transação — um bundle gravado sem os
 * jogos passaria despercebido e só apareceria como janela de exclusão que não
 * funciona.
 */
class CreateBundleUseCase
{
    /**
     * @param  array<string, mixed>  $data  payload já validado pelo StoreBundleRequest
     */
    public function execute(array $data): Bundle
    {
        // `games` chega no mesmo payload mas é pivot, não coluna de bundles.
        $gameIds = $data['games'] ?? [];
        unset($data['games']);

        return DB::transaction(function () use ($data, $gameIds) {
            $bundle = Bundle::create($data);

            if (! empty($gameIds)) {
                $bundle->games()->attach($gameIds);
                $bundle->load(['games' => fn ($query) => $query->orderBy('name', 'asc')]);
            }

            return $bundle;
        });
    }
}
