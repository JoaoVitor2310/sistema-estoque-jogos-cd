<?php

use App\Models\Asset;
use App\Models\Key;
use App\Services\Games\GameService;
use App\Services\KeyService;
use App\Services\AssetService;
use App\UseCases\Bundles\SyncBundlesFromApiUseCase;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::call(function () {
    // Buscar chaves que expiram em 14 dias ou menos (mas que ainda não expiraram e nem foram vendidas)
    $dataLimite = now()->addDays(30);
    $keysAboutToExpire = Key::where('expires_at', '<=', $dataLimite)
        ->where('expires_at', '>', now()) // Ainda não expiradas
        ->whereNull('sold_at') // Não vendidas
        ->get();

    // Só enviar email se houver chaves para expirar
    if ($keysAboutToExpire->count() > 0) {
        try {
            Mail::send('emails.expiration-alert', ['jogos' => $keysAboutToExpire], function ($message) {
                $message->to('carcadeals@gmail.com')
                    ->subject('⚠️ Alerta: '.\Carbon\Carbon::now()->format('d/m/Y').' - Jogos expirando em até 30 dias');
            });

            Log::info('Email de expiração enviado com sucesso. Jogos encontrados: '.$keysAboutToExpire->count());
        } catch (\Exception $e) {
            Log::error('Erro ao enviar email de expiração: '.$e->getMessage());
        }
    }
})->cron('0 7 * * *')->timezone('America/Sao_Paulo');

Schedule::call(function () {
    $tf2 = Asset::where('name', 'TF2')->first();

    // Pegar o preço real de TF2
    $data['currentCurrency'] = 'BRL';
    $data['price_brl'] = $tf2->price_brl;

    $assetService = new AssetService;
    $data = $assetService->getAssetsCurrency($data);

    // Comparar o preço do dolar no sistema com o preço dolar do dia atual
    if ($data['price_dollar'] - $tf2->price_dollar >= 0.20 || $tf2->price_dollar - $data['price_dollar'] >= 0.20) {
        try {
            Mail::send('emails.dolar-alert', ['tf2' => $tf2, 'data' => $data], function ($message) {
                $message->to('carcadeals@gmail.com')
                    ->subject('⚠️ Alerta: '.\Carbon\Carbon::now()->format('d/m/Y').' - Dolar variou mais que 0.20');
            });

            Log::info('Email de alerta de dolar enviado com sucesso. Dolar variou mais que 0.20');
        } catch (\Exception $e) {
            Log::error('Erro ao enviar email de alerta de dolar: '.$e->getMessage());
        }
    }
})->cron('0 7 * * *')->timezone('America/Sao_Paulo');

Schedule::call(function () {
    app(SyncBundlesFromApiUseCase::class)->execute();
})->cron('5 * * * *')->timezone('UTC');

Schedule::call(function () {
    $gameService = new GameService;
    $gameService->searchGamesIdSteam();
})->cron('0 6 * * *')->timezone('America/Sao_Paulo');

Schedule::call(function () {
    $gameService = new GameService;
    $gameService->updateMinPrices();
})->cron('0 6 * * *')->timezone('America/Sao_Paulo');

Schedule::call(function () {
    $keyService = new KeyService;
    $keyService->checkLimboKeys();
})->cron('0 6 * * *')->timezone('America/Sao_Paulo');
