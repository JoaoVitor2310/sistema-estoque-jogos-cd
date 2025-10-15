<?php

namespace App\Services;
use App\Services\APIService;

class ResourceService
{
    public function getResourcesCurrency($data)
    {
        $APIService = new APIService();

        switch ($data['currentCurrency']) {
            case 'BRL':
                $base_price = $data['preco_real'];
                $euro_result = $APIService->convertCurrency('BRL', 'EUR', $base_price);
                if ($euro_result['success']) $data['preco_euro'] = $euro_result['amount'];

                $dolar_result = $APIService->convertCurrency('BRL', 'USD', $base_price);
                if ($dolar_result['success']) $data['preco_dolar'] = $dolar_result['amount'];
                break;
            case 'EUR':
                $base_price = $data['preco_euro'];
                $real_result = $APIService->convertCurrency('EUR', 'BRL', $base_price);
                if ($real_result['success']) $data['preco_real'] = $real_result['amount'];

                $dolar_result = $APIService->convertCurrency('EUR', 'USD', $base_price);
                if ($dolar_result['success']) $data['preco_dolar'] = $dolar_result['amount'];
                break;
            case 'USD':
                $base_price = $data['preco_dolar'];
                $real_result = $APIService->convertCurrency('USD', 'BRL', $base_price);
                if ($real_result['success']) $data['preco_real'] = $real_result['amount'];

                $euro_result = $APIService->convertCurrency('USD', 'EUR', $base_price);
                if ($euro_result['success']) $data['preco_euro'] = $euro_result['amount'];
                break;
        }

        return $data;
    }
}
