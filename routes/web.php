<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuthorizedUsersController;
use App\Http\Controllers\BundleController;
use App\Http\Controllers\FeeController;
use App\Http\Controllers\FinancialController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\Keys\KeyController;
use App\Http\Controllers\Keys\KeyImportController;
use App\Http\Controllers\Keys\KeySaleController;
use App\Http\Controllers\TradeController;
use App\Http\Controllers\VipController;
use App\Http\Middleware\CheckAdmin;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\VerifySecret;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Pages

Route::fallback(function () {
    return redirect()->route('keys');
});

Route::get('/fees', [FeeController::class, 'showMarketPlaceFees'])->name('fees'); // READ all fees

Route::get('/assets', [AssetController::class, 'show'])->name('assets');

Route::get('/bundles', [BundleController::class, 'index'])->name('bundles');

Route::get('/financial', [FinancialController::class, 'show'])->name('financial')->middleware(CheckPermission::class);

Route::prefix('trades')
    ->middleware(CheckPermission::class)
    ->controller(TradeController::class)
    ->group(function () {
        Route::get('/', 'show')->name('trades');
        Route::post('/', 'store')->name('trades.store');
        Route::put('/{trade}', 'update')->name('trades.update');
        Route::delete('/{trade}', 'destroy')->name('trades.destroy');
        Route::post('/{trade}/import', 'importKeys')->name('trades.import');
    });

Route::get('/games', [GameController::class, 'index'])->name('games');

Route::prefix('vips')
    ->middleware(CheckPermission::class)->controller(VipController::class)->group(function () {
        Route::get('/', 'index')->name('vips.index');
        Route::post('/', 'store')->name('vips.store');
        Route::put('/{id}', 'update')->name('vips.update');
        Route::delete('/{id}', 'destroy')->name('vips.destroy');
        Route::post('/run/{vip}', 'runVipList')->name('vips.runVipList');
        Route::post('/callback/{vipList}', 'callbackVipList')
            ->name('vips.callbackVipList')
            ->withoutMiddleware([CheckPermission::class])
            ->middleware(VerifySecret::class);
    });

Route::get('/keys', [KeyController::class, 'show'])->name('keys');

Route::get('/acesso', [AuthorizedUsersController::class, 'index'])->name('acesso');

Route::get('/login', function () {
    return Inertia::render('Login', [
        'props' => 'login',
    ]);
})->name('login');

// API

Route::prefix('auth')->group(function () { // Logar
    // Google
    Route::get('/redirect', [AuthController::class, 'redirectToGoogle'])->name('auth.google.redirect');
    Route::get('/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');
    // As rotas de autenticação do Breeze estão no arquivo ./auth.php
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/logged', [AuthController::class, 'logged'])->name('auth.logged');
});

Route::prefix('fees')
    ->middleware(CheckAdmin::class)
    ->controller(FeeController::class)->group(function () {
        Route::post('/', 'store')->name('fees.store');
        Route::put('/{id}', 'update')->name('fees.update');
        Route::delete('/{id}', 'destroy')->name('fees.destroy');
        Route::delete('/', 'destroyArray')->name('fees.destroyArray');
    });

Route::prefix('games')
    ->middleware(CheckPermission::class)
    ->controller(GameController::class)->group(function () {
        Route::get('/paginated', 'paginated')->name('games.paginated');
        Route::post('/search', 'search')->name('games.search');
        Route::post('/', 'store')->name('games.store');
        Route::put('/{id}', 'update')->name('games.update');
        Route::delete('/{id}', 'destroy')->name('games.destroy');
        Route::delete('/', 'destroyArray')->name('games.destroyArray');
        Route::get('/search-popularity', 'searchPopularity')->name('games.searchPopularity')->withoutMiddleware([CheckPermission::class])->middleware(VerifySecret::class);
        Route::post('/update-popularity', 'updatePopularity')->name('games.updatePopularity')->withoutMiddleware([CheckPermission::class])->middleware(VerifySecret::class);
    });

Route::prefix('bundles')
    ->middleware(CheckPermission::class)
    ->controller(BundleController::class)->group(function () {
        Route::post('/', 'store')->name('bundles.store');
        Route::put('/{id}', 'update')->name('bundles.update');
        Route::delete('/{id}', 'destroy')->name('bundles.destroy');
        Route::post('/{id}/games', 'addGames')->name('bundles.addGames');
        Route::delete('/{id}/games', 'removeGames')->name('bundles.removeGames');
    });

Route::prefix('assets')
    ->middleware(CheckAdmin::class)
    ->controller(AssetController::class)
    ->group(function () {
        Route::post('/', 'store')->name('assets.store');
        Route::put('/{id}', 'update')->name('assets.update');
        Route::delete('/{id}', 'destroy')->name('assets.destroy');
        Route::delete('/', 'destroyArray')->name('assets.destroyArray');
    });

Route::prefix('keys')
    ->middleware(CheckPermission::class)
    ->group(function () {
        // KeyController — CRUD
        Route::get('/paginated', [KeyController::class, 'paginated'])->name('keys.paginated');
        Route::post('/search', [KeyController::class, 'search'])->name('keys.search');
        Route::post('/', [KeyController::class, 'store'])->name('keys.store');
        Route::put('/{id}', [KeyController::class, 'update'])->name('keys.update');
        Route::delete('/{id}', [KeyController::class, 'destroy'])->name('keys.destroy');
        Route::delete('/', [KeyController::class, 'destroyArray'])->name('keys.destroyArray');

        // KeySaleController — operações de venda
        Route::get('/auto-sell', [KeySaleController::class, 'autoSell'])->name('keys.auto-sell')->withoutMiddleware([CheckPermission::class])->middleware(VerifySecret::class);
        Route::get('/when-to-sell', [KeySaleController::class, 'whenToSell'])->name('keys.when-to-sell')->withoutMiddleware([CheckPermission::class])->middleware(VerifySecret::class);
        Route::post('/update-sold-offers', [KeySaleController::class, 'updateSoldOffers'])->name('keys.update-sold-offers')->withoutMiddleware([CheckPermission::class])->middleware(VerifySecret::class);
        Route::get('/search-by-id-gamivo/{idGamivo}', [KeySaleController::class, 'searchByIdGamivo'])->name('keys.search-by-id-gamivo')->withoutMiddleware([CheckPermission::class])->middleware(VerifySecret::class);
        Route::post('/insert-data-venda', [KeySaleController::class, 'insertDataVenda'])->name('keys.insert-data-venda')->withoutMiddleware([CheckPermission::class])->middleware(VerifySecret::class);

        // KeyImportController — importação XLSX
        Route::post('/import', [KeyImportController::class, 'import'])->name('keys.import');
        Route::get('/download-example_keys', [KeyImportController::class, 'downloadExample'])->name('keys.download-example_keys');
    });

Route::prefix('authorize') // Gerenciar quem tem acesso
    ->middleware(CheckAdmin::class) // Somente o admin poderá acessar essas rotas
    ->controller(AuthorizedUsersController::class)->group(function () {
        Route::post('/', 'store')->name('authorize.store');
        Route::put('/{id}', 'update')->name('authorize.update');
        Route::delete('/{id}', 'destroy')->name('authorize.destroy');
        Route::delete('/', 'destroyArray')->name('authorize.destroyArray');
    });

//Breeze

// Route::get('/', function () {
//     return Inertia::render('Welcome', [
//         'canLogin' => Route::has('login'),
//         'canRegister' => Route::has('register'),
//         'laravelVersion' => Application::VERSION,
//         'phpVersion' => PHP_VERSION,
//     ]);
// });

Route::get('/dashboard', function () {
    return redirect(route('keys', absolute: false));
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', function () {
        return redirect(route('keys', absolute: false));
    })->name('profile.edit');

    Route::patch('/profile', function () {
        return redirect(route('keys', absolute: false));
    })->name('profile.update');

    Route::delete('/profile', function () {
        return redirect(route('keys', absolute: false));
    })->name('profile.destroy');
});

require __DIR__.'/auth.php';
