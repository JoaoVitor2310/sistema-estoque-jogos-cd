<?php

namespace App\UseCases\Keys;

use App\Services\Keys\KeyRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * Retorna keys elegíveis para listagem automática no Gamivo.
 * Orquestra: KeyRepository (consulta).
 *
 * Bundles já vêm eager-loaded ordenados por release_date desc —
 * o controller ou Resource acessa ->game->bundles->first() para
 * obter o bundle mais recente de cada key.
 */
class AutoSellUseCase
{
    public function __construct(
        private KeyRepository $keyRepository,
    ) {}

    /**
     * @return Collection<int, \App\Models\Venda_chave_troca>
     */
    public function execute(): Collection
    {
        return $this->keyRepository->findEligibleForAutoSell();
    }
}
