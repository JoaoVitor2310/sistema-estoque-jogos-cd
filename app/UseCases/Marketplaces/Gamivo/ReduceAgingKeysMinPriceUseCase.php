<?php

namespace App\UseCases\Marketplaces\Gamivo;

use App\Domain\Keys\KeyEligibility;
use App\Models\Key;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Reduz o piso de preço (min_api) de keys que estão paradas no estoque há 7+ meses.
 *
 * Com min_api mais baixo, o AutoSellUseCase consegue listar essas keys mesmo quando
 * o mercado caiu abaixo do piso original calculado no momento da aquisição.
 *
 * O novo min_api é definido como individual_cost × AGING_KEY_MIN_API_MULTIPLIER (1.2),
 * garantindo margem positiva de 20% mesmo com o piso reduzido. A redução é aplicada
 * apenas quando o min_api atual é maior que o novo valor — nunca aumenta o piso.
 *
 * Este UseCase deve rodar diariamente antes do AutoSellUseCase para que as keys
 * elegíveis já tenham o piso atualizado quando o auto-sell processar.
 *
 * ⚠️  Não chama a API Gamivo. Apenas atualiza registros no banco.
 */
class ReduceAgingKeysMinPriceUseCase
{
    /**
     * Atualiza min_api das keys com >= AGING_KEY_MONTHS meses no estoque.
     *
     * @return int[] IDs das keys atualizadas
     */
    public function execute(): array
    {
        // Janela de aging: >= AGING_KEY_MONTHS e < OLD_KEY_MONTHS.
        // Keys com >= OLD_KEY_MONTHS são responsabilidade exclusiva do AutoSellUseCase
        // (age override) e não devem ser tocadas aqui.
        $agingCutoff = Carbon::now()->subMonths(KeyEligibility::AGING_KEY_MONTHS);
        $oldKeyCutoff = Carbon::now()->subMonths(KeyEligibility::OLD_KEY_MONTHS);

        $keys = Key::query()
            ->withGamivoId()
            ->notYetListed()
            ->whereNotNull('acquired_at')
            ->where('acquired_at', '<=', $agingCutoff->toDateString())
            ->where('acquired_at', '>', $oldKeyCutoff->toDateString())
            ->get();

        $updated = [];

        foreach ($keys as $key) {
            $cost = (float) $key->individual_cost;
            $newMinApi = round($cost * KeyEligibility::AGING_KEY_MIN_API_MULTIPLIER, 2);
            $currentMinApi = (float) $key->min_api;

            // Só reduz — nunca aumenta o piso
            if ($currentMinApi <= $newMinApi) {
                continue;
            }

            $key->update(['min_api' => $newMinApi]);
            $updated[] = $key->id;
        }

        Log::info('ReduceAgingKeysMinPriceUseCase: concluído', [
            'candidates' => count($keys),
            'updated' => count($updated),
        ]);

        return $updated;
    }
}
