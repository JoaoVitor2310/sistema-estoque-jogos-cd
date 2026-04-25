<?php

namespace App\Services;

use App\Services\External\CurrencyConversionService;

class AssetService
{
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
