<?php

use App\Services\AssetService;
use App\Services\Games\GameService;
use App\Services\KeyService;
use App\UseCases\Bundles\SyncBundlesFromApiUseCase;
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
