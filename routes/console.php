<?php

use App\Models\Bundle;
use App\Models\Recursos;
use App\Models\Venda_chave_troca;
use App\Services\ResourceService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::call(function () {
    // Buscar chaves que expiram em 14 dias ou menos (mas que ainda não expiraram e nem foram vendidas)
    $dataLimite = now()->addDays(14);
    $keysAboutToExpire = Venda_chave_troca::where('dataExpiracao', '<=', $dataLimite)
        ->where('dataExpiracao', '>', now()) // Ainda não expiradas
        ->whereNull('dataVendida') // Não vendidas
        ->get();

    // Só enviar email se houver chaves para expirar
    if ($keysAboutToExpire->count() > 0) {
        try {
            Mail::send('emails.expiration-alert', ['jogos' => $keysAboutToExpire], function ($message) {
                $message->to('carcadeals@gmail.com')
                    ->subject('⚠️ Alerta: ' . \Carbon\Carbon::now()->format('d/m/Y') . ' - Jogos expirando em até 14 dias');
            });

            Log::info('Email de expiração enviado com sucesso. Jogos encontrados: ' . $keysAboutToExpire->count());
        } catch (\Exception $e) {
            Log::error('Erro ao enviar email de expiração: ' . $e->getMessage());
        }
    }
})->cron('0 7 * * *')->timezone('America/Sao_Paulo');



// Artisan::command('teste', function () {
Schedule::call(function () {
    $tf2 = Recursos::where('name', 'TF2')->first();

    // Pegar o preço real de TF2
    $data['currentCurrency'] = 'BRL';
    $data['preco_real'] = $tf2->preco_real;

    $resourceService = new ResourceService();
    $data = $resourceService->getResourcesCurrency($data);

    // Comparar o preço do dolar no sistema com o preço dolar do dia atual
    if ($data['preco_dolar'] - $tf2->preco_dolar >= 0.20 || $tf2->preco_dolar - $data['preco_dolar'] >= 0.20) {
        try {
            Mail::send('emails.dolar-alert', ['tf2' => $tf2, 'data' => $data], function ($message) {
                $message->to('carcadeals@gmail.com')
                    ->subject('⚠️ Alerta: ' . \Carbon\Carbon::now()->format('d/m/Y') . ' - Dolar variou mais que 0.20');
            });

            Log::info('Email de alerta de dolar enviado com sucesso. Dolar variou mais que 0.20');
        } catch (\Exception $e) {
            Log::error('Erro ao enviar email de alerta de dolar: ' . $e->getMessage());
        }
    }
})->cron('0 7 * * *')->timezone('America/Sao_Paulo');
// });

Artisan::command('teste', function () {
    $response = Http::withOptions([
        'verify' => false
    ])->get('http://api.gg.deals/v1/bundles/active/', [
        'key' => 'JfAiWhbJts9jgVVo7vC6t6e8HIQfiHSN'
    ]);

    if ($response->successful()) {
        $data = $response->json(); // Retorna o JSON decodificado como array
        
        $bundles = $response['data']['bundles'];

        foreach($bundles as $bundle){
            $bundle = Bundle::where('name');
        }
        dd($bundles);
    } else {
        // Tratar erro
        dd('Erro na requisição: ' . $response->status());
    }
});
