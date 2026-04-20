<?php

namespace App\UseCases\Keys;

use App\Services\Keys\KeyCalculationService;
use App\Services\Keys\KeyRepository;

/**
 * Atualiza keys vendidas com dados de venda recebidos da API Gamivo.
 * Orquestra: KeyRepository (busca) + KeyCalculationService (cálculos de venda).
 */
class UpdateSoldOffersUseCase
{
    public function __construct(
        private KeyRepository $keyRepository,
        private KeyCalculationService $calculationService,
    ) {}

    /**
     * @param  array<int, array{keys: string[], profit: numeric, saleDate: string}> $soldGames
     * @return array<int, mixed> Keys que falharam na atualização
     */
    public function execute(array $soldGames): array
    {
        $notUpdated = [];

        foreach ($soldGames as $game) {
            foreach ($game['keys'] as $keyCode) {
                $key = $this->keyRepository->findByKeyCode($keyCode);

                if (!$key || $key->valorVendido) continue;

                $saleFormulas = $this->calculationService->calculateSaleFormulas(
                    (float) $game['profit'],
                    (float) $key->valorPagoIndividual,
                );

                $updated = $key->update([
                    'dataVendida'          => $game['saleDate'],
                    'valorVendido'         => $game['profit'],
                    'lucroVendaRS'         => $saleFormulas['lucroVendaRS'],
                    'lucroVendaPercentual' => $saleFormulas['lucroVendaPercentual'],
                ]);

                if (!$updated) $notUpdated[] = $key;
            }
        }

        return $notUpdated;
    }
}
