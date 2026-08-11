<?php

namespace App\UseCases\Assets;

use App\Domain\Assets\AssetAlert;
use App\Mail\DollarVariationAlertMail;
use App\Models\Asset;
use App\Services\External\CurrencyConversionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa por e-mail quando a cotação do dólar guardada para o TF2 se afastou da
 * cotação real além do limiar de `AssetAlert`.
 *
 * O preço em real é a âncora: a conversão parte dele e o resultado em dólar é
 * comparado com o valor persistido. Quem corrige o cadastro é uma pessoa — o
 * alerta não reescreve o Asset sozinho, porque trocar o preço muda o custo
 * calculado de toda trade nova.
 *
 * Roda no scheduler diário (ver routes/console.php).
 */
class AlertDollarVariationUseCase
{
    public function __construct(
        private readonly CurrencyConversionService $currencyService,
    ) {}

    /**
     * @return bool se o alerta foi disparado
     */
    public function execute(): bool
    {
        $tf2 = Asset::where('name', 'TF2')->first();

        if (! $tf2) {
            return false;
        }

        $currentPrices = $this->currencyService->convertAll('BRL', (float) $tf2->price_brl);

        // Sem cotação em dólar não há comparação possível: a API falhou e
        // qualquer alerta agora seria alarme falso sobre um número inventado.
        if (! isset($currentPrices['price_dollar'])) {
            Log::warning('Dollar variation alert skipped: exchange rate unavailable.');

            return false;
        }

        if (! AssetAlert::hasDrifted((float) $tf2->price_dollar, $currentPrices['price_dollar'])) {
            return false;
        }

        // Falha de envio não pode derrubar o scheduler (ver AlertExpiringKeysUseCase).
        try {
            Mail::to(config('app.admin_email'))->send(new DollarVariationAlertMail($tf2, $currentPrices));

            Log::info('Dollar variation alert sent. Stored: '.$tf2->price_dollar.' Current: '.$currentPrices['price_dollar']);
        } catch (\Exception $e) {
            Log::error('Failed to send dollar variation alert: '.$e->getMessage());
        }

        return true;
    }
}
