<?php

namespace App\UseCases\Assets;

use App\Models\Asset;
use App\Services\External\CurrencyConversionService;

/**
 * Atualiza um ativo de troca, convertendo os preços quando a tela declara uma
 * moeda âncora.
 *
 * Declarar a âncora significa "este é o valor que eu sei; derive os outros".
 * Sem âncora, os três preços são gravados como vieram do formulário.
 *
 * Conversão que falha **não** entra no retorno do CurrencyConversionService, e é
 * disso que depende a correção aqui: o preço enviado permanece em vez de ser
 * sobrescrito pelo montante na moeda de origem, que passaria por cotação real.
 */
class UpdateAssetPricesUseCase
{
    public function __construct(
        private readonly CurrencyConversionService $currencyService,
    ) {}

    /**
     * @param  array<string, mixed>  $data  payload já validado pelo StoreAssetRequest
     */
    public function execute(Asset $asset, array $data): Asset
    {
        $currency = $data['currentCurrency'] ?? '';
        $baseField = CurrencyConversionService::PRICE_FIELD_BY_CURRENCY[$currency] ?? null;

        if ($baseField !== null) {
            $data = array_merge($data, $this->currencyService->convertAll(
                $currency,
                (float) $data[$baseField],
            ));
        }

        $asset->fill($data);
        $asset->save();

        return $asset;
    }
}
