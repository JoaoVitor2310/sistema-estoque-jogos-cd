<?php

namespace App\Services;

use App\Services\External\CurrencyConversionService;

class ResourceService
{
    public function getResourcesCurrency($data)
    {
        $currencyService = new CurrencyConversionService;

        switch ($data['currentCurrency']) {
            case 'BRL':
                $base_price = $data['preco_real'];
                $euro_result = $currencyService->convertCurrency('BRL', 'EUR', $base_price);
                if ($euro_result['success']) {
                    $data['preco_euro'] = $euro_result['amount'];
                }

                $dolar_result = $currencyService->convertCurrency('BRL', 'USD', $base_price);
                if ($dolar_result['success']) {
                    $data['preco_dolar'] = $dolar_result['amount'];
                }
                break;
            case 'EUR':
                $base_price = $data['preco_euro'];
                $real_result = $currencyService->convertCurrency('EUR', 'BRL', $base_price);
                if ($real_result['success']) {
                    $data['preco_real'] = $real_result['amount'];
                }

                $dolar_result = $currencyService->convertCurrency('EUR', 'USD', $base_price);
                if ($dolar_result['success']) {
                    $data['preco_dolar'] = $dolar_result['amount'];
                }
                break;
            case 'USD':
                $base_price = $data['preco_dolar'];
                $real_result = $currencyService->convertCurrency('USD', 'BRL', $base_price);
                if ($real_result['success']) {
                    $data['preco_real'] = $real_result['amount'];
                }

                $euro_result = $currencyService->convertCurrency('USD', 'EUR', $base_price);
                if ($euro_result['success']) {
                    $data['preco_euro'] = $euro_result['amount'];
                }
                break;
        }

        return $data;
    }
}
