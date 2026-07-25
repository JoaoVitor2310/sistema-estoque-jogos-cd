<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuthorizedUsersController;
use App\Http\Controllers\BundleController;
use App\Http\Controllers\FeeController;
use App\Http\Controllers\FinancialController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\Keys\KeyController;
use App\Http\Controllers\Keys\KeySaleController;
use App\Http\Controllers\Suppliers\SupplierController;
use App\Http\Controllers\TradeController;
use App\Http\Middleware\CheckAdmin;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\RequireAuth;
use App\Http\Middleware\VerifySecret;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Pages

Route::fallback(function () {
    return redirect()->route('keys');
});

Route::get('/fees', [FeeController::class, 'showMarketPlaceFees'])->name('fees')->middleware(RequireAuth::class);

Route::get('/assets', [AssetController::class, 'show'])->name('assets')->middleware(RequireAuth::class);

Route::get('/bundles', [BundleController::class, 'index'])->name('bundles'); // público — visitantes podem ver

Route::get('/financial', [FinancialController::class, 'show'])->name('financial')->middleware(RequireAuth::class);

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

Route::get('/games', [GameController::class, 'index'])->name('games')->middleware(RequireAuth::class);

Route::prefix('suppliers')
    ->middleware(CheckPermission::class)
    ->controller(SupplierController::class)
    ->group(function () {
        Route::get('/', 'index')->name('suppliers.index');
        Route::post('/', 'store')->name('suppliers.store');
        Route::put('/{supplier}', 'update')->name('suppliers.update');
        Route::delete('/{supplier}', 'destroy')->name('suppliers.destroy');
        Route::post('/execute/{supplier}', 'executeList')->name('suppliers.executeList');
        Route::post('/find-new', 'findNewSuppliers')->name('suppliers.findNew');
    });

Route::get('/keys', [KeyController::class, 'show'])->name('keys');

// Leitura paginada — acessível a visitantes, mas com campos filtrados no controller
Route::get('/keys/paginated', [KeyController::class, 'paginated'])->name('keys.paginated');
Route::post('/keys/search', [KeyController::class, 'search'])->name('keys.search');

// API externa — Price Researcher
// Autenticado via Bearer token (EXTERNAL_SECRET). Guest: 401. can-edit: não exigido.
Route::post('/suppliers/prospect', [SupplierController::class, 'prospect'])
    ->name('suppliers.prospect')
    ->middleware(VerifySecret::class);

Route::post('/trades/from-price-researcher', [TradeController::class, 'storeFromPriceResearcher'])
    ->name('trades.from-price-researcher')
    ->middleware(VerifySecret::class);

Route::get('/acesso', [AuthorizedUsersController::class, 'index'])->name('acesso')->middleware(RequireAuth::class);

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
        // KeyController — edição/remoção (mutações exigem permissão).
        // Não há rota de criação: keys entram só via POST /trades/{trade}/import.
        Route::put('/{key}', [KeyController::class, 'update'])->name('keys.update');
        Route::delete('/{id}', [KeyController::class, 'destroy'])->name('keys.destroy');
        Route::delete('/', [KeyController::class, 'destroyArray'])->name('keys.destroyArray');

        // KeySaleController — operações de venda
        Route::get('/auto-sell', [KeySaleController::class, 'autoSell'])->name('keys.auto-sell')->withoutMiddleware([CheckPermission::class])->middleware(VerifySecret::class);
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
