<?php

namespace App\UseCases\Marketplaces\Gamivo;

use App\Domain\Keys\KeyEligibility;
use App\Models\Key;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Reduz o piso de preço (min_api) de keys paradas no estoque, em dois tiers de idade:
 *
 *  - >= MODERATE_AGE_MONTHS (4) e < AGING_KEY_MONTHS (7) meses:
 *      min_api = individual_cost × MODERATE_AGE_MIN_API_MULTIPLIER (1.5) → margem de 50%
 *  - >= AGING_KEY_MONTHS (7) e < OLD_KEY_MONTHS (10) meses:
 *      min_api = individual_cost × AGING_KEY_MIN_API_MULTIPLIER (1.2)    → margem de 20%
 *
 * Com min_api mais baixo, o AutoSellUseCase consegue listar essas keys mesmo quando
 * o mercado caiu abaixo do piso original calculado no momento da aquisição.
 * Os multiplicadores são coerentes com os thresholds de hasMinimumProfitForAutoSell.
 * A redução é aplicada apenas quando o min_api atual supera o novo valor — nunca aumenta.
 *
 * Este UseCase deve rodar diariamente antes do AutoSellUseCase para que as keys
 * elegíveis já tenham o piso atualizado quando o auto-sell processar.
 *
 * ⚠️  Não chama a API Gamivo. Apenas atualiza registros no banco.
 */
class ReduceAgingKeysMinPriceUseCase
{
    /**
     * Atualiza min_api das keys com >= MODERATE_AGE_MONTHS e < OLD_KEY_MONTHS meses no estoque.
     *
     * @return int[] IDs das keys atualizadas
     */
    public function execute(): array
    {
        // Janela total: >= MODERATE_AGE_MONTHS e < OLD_KEY_MONTHS.
        // Keys com >= OLD_KEY_MONTHS são responsabilidade exclusiva do AutoSellUseCase
        // (age override) e não devem ser tocadas aqui.
        $moderateCutoff = Carbon::now()->subMonths(KeyEligibility::MODERATE_AGE_MONTHS);
        $oldKeyCutoff = Carbon::now()->subMonths(KeyEligibility::OLD_KEY_MONTHS);

        $keys = Key::query()
            ->withGamivoId()
            ->notYetListed()
            ->whereNotNull('acquired_at')
            ->where('acquired_at', '<=', $moderateCutoff->toDateString())
            ->where('acquired_at', '>', $oldKeyCutoff->toDateString())
            ->get();

        $updated = [];
        $updatedDetails = [];

        foreach ($keys as $key) {
            $cost = (float) $key->individual_cost;
            $acquiredAt = Carbon::parse($key->acquired_at);

            // Tier de aging: ≥ 7 meses → 1.2×; ≥ 4 meses → 1.5×
            $multiplier = $acquiredAt->lt(Carbon::now()->subMonths(KeyEligibility::AGING_KEY_MONTHS))
                ? KeyEligibility::AGING_KEY_MIN_API_MULTIPLIER
                : KeyEligibility::MODERATE_AGE_MIN_API_MULTIPLIER;

            $newMinApi = round($cost * $multiplier, 2);
            $currentMinApi = (float) $key->min_api;

            // Só reduz — nunca aumenta o piso
            if ($currentMinApi <= $newMinApi) {
                continue;
            }

            $key->update(['min_api' => $newMinApi]);
            $updated[] = $key->id;
            $updatedDetails[] = [
                'key_id' => $key->id,
                'key_code' => $key->key_code,
                'game_name' => $key->game_name,
                'old_min_api' => $currentMinApi,
                'new_min_api' => $newMinApi,
            ];
        }

        Log::channel('schedulers')->info('ReduceAgingKeysMinPriceUseCase', [
            'candidates' => count($keys),
            'updated' => count($updated),
            'updated_details' => $updatedDetails,
        ]);

        return $updated;
    }
}
