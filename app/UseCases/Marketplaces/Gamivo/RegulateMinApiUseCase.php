<?php

namespace App\UseCases\Marketplaces\Gamivo;

use App\Domain\Pricing\MinimumMarginPolicy;
use App\Models\Key;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Recalcula min_api de todas as keys não vendidas — listadas ou não — a
 * partir de MinimumMarginPolicy, fonte única do piso de preço.
 *
 * O recálculo é sempre completo (sem trava de "só reduz"): MinimumMarginPolicy
 * é determinística e correta nos dois sentidos (ex: uma key deixa de "expirar
 * em breve" se expires_at for corrigido, e o min_api deve refletir isso).
 *
 * Deve rodar diariamente antes do AutoSellUseCase para que o piso já esteja
 * atualizado quando o auto-sell processar.
 *
 * ⚠️  Não chama a API Gamivo. Apenas atualiza registros no banco.
 */
class RegulateMinApiUseCase
{
    /**
     * Atualiza min_api de todas as keys elegíveis (não vendidas, com
     * gamivo_id e acquired_at preenchidos).
     *
     * @return int[] IDs das keys atualizadas
     */
    public function execute(): array
    {
        $keys = Key::query()
            ->withGamivoId()
            ->whereNull('sold_at')
            ->whereNotNull('acquired_at')
            ->get();

        $updated = [];
        $updatedDetails = [];

        foreach ($keys as $key) {
            $newMinApi = MinimumMarginPolicy::minApi(
                individualCost: (float) $key->individual_cost,
                acquiredAt: Carbon::parse($key->acquired_at),
                listedAt: $key->listed_at !== null ? Carbon::parse($key->listed_at) : null,
                expiresAt: $key->expires_at !== null ? Carbon::parse($key->expires_at) : null,
            );

            $currentMinApi = (float) $key->min_api;

            if (abs($currentMinApi - $newMinApi) < 0.001) {
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

        Log::channel('schedulers')->info('RegulateMinApiUseCase', [
            'candidates' => count($keys),
            'updated' => count($updated),
            'updated_details' => $updatedDetails,
        ]);

        return $updated;
    }
}
