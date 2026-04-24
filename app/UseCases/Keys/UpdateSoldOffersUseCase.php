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
     * @param  array<int, array{keys: string[], profit: numeric, saleDate: string}>  $soldGames
     * @return array<int, mixed> Keys que falharam na atualização
     */
    public function execute(array $soldGames): array
    {
        $notUpdated = [];

        foreach ($soldGames as $game) {
            foreach ($game['keys'] as $keyCode) {
                $key = $this->keyRepository->findByKeyCode($keyCode);

                if (! $key || $key->sold_price) {
                    continue;
                }

                $saleFormulas = $this->calculationService->calculateSaleFormulas(
                    (float) $game['profit'],
                    (float) $key->individual_cost,
                );

                $updated = $key->update([
                    'sold_at' => $game['saleDate'],
                    'sold_price' => $game['profit'],
                    'sale_profit' => $saleFormulas['sale_profit'],
                    'sale_profit_percent' => $saleFormulas['sale_profit_percent'],
                ]);

                if (! $updated) {
                    $notUpdated[] = $key;
                }
            }
        }

        return $notUpdated;
    }
}
