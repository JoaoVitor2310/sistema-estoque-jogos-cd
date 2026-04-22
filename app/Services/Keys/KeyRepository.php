<?php

namespace App\Services\Keys;

use App\Domain\Keys\KeyEligibility;
use App\Models\Venda_chave_troca;
use Illuminate\Database\Eloquent\Collection;

/**
 * Queries complexas sobre venda_chave_trocas.
 * Infraestrutura pura — sem lógica de negócio.
 */
class KeyRepository
{
    /**
     * Busca uma key pelo código de ativação.
     * Quando $excludeId é fornecido, ignora o próprio registro (útil no update).
     */
    public function findByKeyCode(string $keyCode, ?int $excludeId = null): ?Venda_chave_troca
    {
        return Venda_chave_troca::where('chaveRecebida', $keyCode)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->first();
    }

    /**
     * Retorna keys elegíveis para listagem automática no Gamivo.
     *
     * Regras aplicadas via local scopes (ver Venda_chave_troca):
     *  - registeredOnGamivo: idGamivo preenchido
     *  - notYetListed: dataVenda e dataVendida nulas
     *  - notGiftLink: chaveRecebida sem URL
     *  - withoutRecentBundle: jogo fora de bundles dos últimos N dias
     *
     * Eager loading traz o jogo e seus bundles ordenados por release_date desc,
     * permitindo que o UseCase selecione o bundle mais recente (um por key).
     *
     * @return Collection<int, Venda_chave_troca>
     */
    public function findEligibleForAutoSell(): Collection
    {
        return Venda_chave_troca::query()
            ->registeredOnGamivo()
            ->notYetListed()
            ->notGiftLink()
            ->withoutRecentBundle(KeyEligibility::BUNDLE_EXCLUSION_DAYS)
            ->with(['game.bundles' => fn ($q) => $q->latest('release_date')])
            ->get();
    }
}
