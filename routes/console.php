<?php

use App\UseCases\Assets\AlertDollarVariationUseCase;
use App\UseCases\Bundles\SyncBundlesFromApiUseCase;
use App\UseCases\Games\ResolveSteamIdsUseCase;
use App\UseCases\Keys\AlertExpiringKeysUseCase;
use App\UseCases\Marketplaces\Gamivo\AutoSellUseCase;
use App\UseCases\Marketplaces\Gamivo\RegulateMinApiUseCase;
use App\UseCases\Marketplaces\Gamivo\UpdateOffersUseCase;
use App\UseCases\Marketplaces\Gamivo\UpdatePopularityUseCase;
use App\UseCases\Marketplaces\Gamivo\UpdateSoldOffersUseCase;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Avisa sobre keys perto de expirar — depois do prazo elas não valem mais nada
Schedule::call(fn () => app(AlertExpiringKeysUseCase::class)->execute())
    ->cron('0 7 * * *')->timezone('America/Sao_Paulo')->environments('production');

// Avisa quando a cotação guardada do TF2 se afasta da real, distorcendo o custo
// calculado das trades novas
Schedule::call(fn () => app(AlertDollarVariationUseCase::class)->execute())
    ->cron('0 7 * * *')->timezone('America/Sao_Paulo')->environments('production');

Schedule::call(fn () => app(SyncBundlesFromApiUseCase::class)->execute())
    ->cron('5 * * * *')->timezone('UTC')->environments('production');

// Descobre o steam_id dos jogos que ainda não têm um, via price_researcher.
// Pré-requisito da atualização de popularidade, que roda uma hora depois.
Schedule::call(fn () => app(ResolveSteamIdsUseCase::class)->execute())
    ->cron('0 6 * * *')->timezone('America/Sao_Paulo')->environments('production');

// Recalcula min_api de todas as keys não vendidas (listadas ou não) a partir
// de MinimumMarginPolicy — fonte única do piso de preço. Sem chamadas à API —
// apenas DB. Deve rodar antes do AutoSell para que o piso já esteja
// atualizado na listagem.
Schedule::call(fn () => app(RegulateMinApiUseCase::class)->execute())
    ->cron('30 7 * * *')->timezone('America/Sao_Paulo')->environments('production');

// Baixa das keys vendidas na Gamivo (janela de 30 dias para cobrir vendas não processadas)
Schedule::call(fn () => app(UpdateSoldOffersUseCase::class)->executeFromGamivo())
    ->cron('0 6,18 * * *')->timezone('America/Sao_Paulo')->environments('production');

// Atualização de popularidade dos jogos via SteamCharts
Schedule::call(fn () => app(UpdatePopularityUseCase::class)->execute())
    ->cron('0 7 * * *')->timezone('America/Sao_Paulo')->environments('production');

// Reprecificação a cada minuto: uma única passada processa todas as ofertas ativas,
// subindo preço onde já somos os mais baratos e descendo onde não somos — um único
// processo por tick evita qualquer concorrência entre os dois sentidos contra a API Gamivo
Schedule::call(fn () => app(UpdateOffersUseCase::class)->execute())
    ->name('update-offers')
    ->withoutOverlapping()
    ->cron('* * * * *')->timezone('America/Sao_Paulo')->environments('production');

Artisan::command('gamivo:auto-sell', function () {
    $listed = app(AutoSellUseCase::class)->execute();
    $this->info(count($listed).' key(s) listada(s): '.implode(', ', $listed));
})->purpose('Lista keys elegíveis na Gamivo (AutoSellUseCase)');
