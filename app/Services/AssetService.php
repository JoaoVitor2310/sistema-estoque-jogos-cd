<?php

namespace App\Services;

use App\Domain\Assets\AssetAlert;
use App\Models\Asset;
use App\Services\External\CurrencyConversionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AssetService
{
    /**
     * Envia alerta por e-mail quando o preço do dólar do TF2 varia mais de 0.20
     * em relação ao valor armazenado no sistema.
     */
    public function checkDollarAlert(): void
    {
        $tf2 = Asset::where('name', 'TF2')->first();

        if (! $tf2) {
            return;
        }

        $data = $this->getAssetsCurrency([
            'currentCurrency' => 'BRL',
            'price_brl' => $tf2->price_brl,
        ]);

        if (abs($data['price_dollar'] - $tf2->price_dollar) < AssetAlert::DOLLAR_PRICE_VARIATION_THRESHOLD) {
            return;
        }

        try {
            Mail::send('emails.dolar-alert', ['tf2' => $tf2, 'data' => $data], function ($message) {
                $message->to('carcadeals@gmail.com')
                    ->subject('⚠️ Alerta: '.Carbon::now()->format('d/m/Y').' - Dolar variou mais que 0.20');
            });

            Log::info('Email de alerta de dolar enviado com sucesso. Dolar variou mais que 0.20');
        } catch (\Exception $e) {
            Log::error('Erro ao enviar email de alerta de dolar: '.$e->getMessage());
        }
    }

    public function getAssetsCurrency($data)
    {
        $currencyService = new CurrencyConversionService;

        switch ($data['currentCurrency']) {
            case 'BRL':
                $base_price = $data['price_brl'];
                $euro_result = $currencyService->convertCurrency('BRL', 'EUR', $base_price);
                if ($euro_result['success']) {
                    $data['price_euro'] = $euro_result['amount'];
                }

                $dolar_result = $currencyService->convertCurrency('BRL', 'USD', $base_price);
                if ($dolar_result['success']) {
                    $data['price_dollar'] = $dolar_result['amount'];
                }
                break;
            case 'EUR':
                $base_price = $data['price_euro'];
                $real_result = $currencyService->convertCurrency('EUR', 'BRL', $base_price);
                if ($real_result['success']) {
                    $data['price_brl'] = $real_result['amount'];
                }

                $dolar_result = $currencyService->convertCurrency('EUR', 'USD', $base_price);
                if ($dolar_result['success']) {
                    $data['price_dollar'] = $dolar_result['amount'];
                }
                break;
            case 'USD':
                $base_price = $data['price_dollar'];
                $real_result = $currencyService->convertCurrency('USD', 'BRL', $base_price);
                if ($real_result['success']) {
                    $data['price_brl'] = $real_result['amount'];
                }

                $euro_result = $currencyService->convertCurrency('USD', 'EUR', $base_price);
                if ($euro_result['success']) {
                    $data['price_euro'] = $euro_result['amount'];
                }
                break;
        }

        return $data;
    }
}
