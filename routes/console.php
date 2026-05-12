<?php

use App\Services\AssetService;
use App\Services\Games\GameService;
use App\Services\KeyService;
use App\UseCases\Bundles\SyncBundlesFromApiUseCase;
use App\UseCases\Marketplaces\Gamivo\AutoSellUseCase;
use App\UseCases\Marketplaces\Gamivo\ReduceAgingKeysMinPriceUseCase;
use App\UseCases\Marketplaces\Gamivo\UpdateOffersUseCase;
use App\UseCases\Marketplaces\Gamivo\UpdatePopularityUseCase;
use App\UseCases\Marketplaces\Gamivo\UpdateSoldOffersUseCase;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::call(fn () => app(KeyService::class)->checkExpiringKeys())
    ->cron('0 7 * * *')->timezone('America/Sao_Paulo');

Schedule::call(fn () => app(AssetService::class)->checkDollarAlert())
    ->cron('0 7 * * *')->timezone('America/Sao_Paulo');

Schedule::call(fn () => app(SyncBundlesFromApiUseCase::class)->execute())
    ->cron('5 * * * *')->timezone('UTC');

Schedule::call(fn () => app(GameService::class)->searchGamesIdSteam())
    ->cron('0 6 * * *')->timezone('America/Sao_Paulo');

Schedule::call(fn () => app(GameService::class)->updateMinPrices())
    ->cron('0 6 * * *')->timezone('America/Sao_Paulo');

Schedule::call(fn () => app(KeyService::class)->checkLimboKeys())
    ->cron('0 6 * * *')->timezone('America/Sao_Paulo');

Schedule::call(fn () => app(KeyService::class)->reduceExpiringListedKeysPrice())
    ->cron('0 6 * * *')->timezone('America/Sao_Paulo');

// Reduz min_api de keys com >= 7 meses no estoque (apenas DB, sem chamadas à API)
// Deve rodar antes do AutoSell para que o piso já esteja atualizado na listagem.
Schedule::call(fn () => app(ReduceAgingKeysMinPriceUseCase::class)->execute())
    ->cron('30 7 * * *')->timezone('America/Sao_Paulo');

// Listagem automática de keys elegíveis na Gamivo
// Schedule::call(fn () => app(AutoSellUseCase::class)->execute())
//     ->cron('0 8 * * *')->timezone('America/Sao_Paulo');

// Reprecificação horária de todas as ofertas ativas na Gamivo
Schedule::call(fn () => app(UpdateOffersUseCase::class)->execute())
    ->cron('5 * * * *')->timezone('America/Sao_Paulo');

// Baixa das keys vendidas na Gamivo (janela de 2 dias para cobrir bordas de fuso)
Schedule::call(fn () => app(UpdateSoldOffersUseCase::class)->executeFromGamivo())
    ->cron('0 7 * * *')->timezone('America/Sao_Paulo');

// Atualização de popularidade dos jogos via SteamCharts
Schedule::call(fn () => app(UpdatePopularityUseCase::class)->execute())
    ->cron('0 7 * * *')->timezone('America/Sao_Paulo');
