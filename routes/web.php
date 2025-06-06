<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuthorizedUsersController;
use App\Http\Controllers\TaxaController;
use App\Http\Controllers\VendaChaveTrocaController;
use App\Http\Controllers\ResourceController;
use App\Http\Middleware\CheckAdmin;
use App\Http\Middleware\CheckPermission;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Pages

Route::fallback(function () {
    return redirect()->route('venda-chave-troca');
});

Route::get('/fees', [TaxaController::class, 'showMarketPlaceFees'])->name('fees'); // READ all fees

Route::get('/ranges-taxa-G2A', [TaxaController::class, 'showRangesG2A'])->name('ranges-taxa-G2A');

Route::get('/resources', [ResourceController::class, 'show'])->name('resources');

Route::get('/venda-chave-troca', [VendaChaveTrocaController::class, 'show'])->name('venda-chave-troca');

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
    ->controller(TaxaController::class)->group(function () {
        Route::post('/', 'store')->name('fees.store');
        Route::put('/{id}', 'update')->name('fees.update');
        Route::delete('/{id}', 'destroy')->name('fees.destroy');
        Route::delete('/', 'destroyArray')->name('fees.destroyArray');
    });

Route::prefix('ranges-g2a')
    ->middleware(CheckAdmin::class)
    ->controller(TaxaController::class)->group(function () {
        Route::post('/', 'storeRangeG2A')->name('ranges-g2a.storeRangeG2A');
        Route::put('/{id}', 'updateRangeG2A')->name('ranges-g2a.updateRangeG2A');
        Route::delete('/{id}', 'destroyRangeG2A')->name('ranges-g2a.destroyRangeG2A');
        Route::delete('/', 'destroyArrayG2A')->name('ranges-g2a.destroyArrayG2A');
    });

Route::prefix('resources')
    ->middleware(CheckAdmin::class)
    ->controller(ResourceController::class)
    ->group(function () {
        Route::post('/', 'store')->name('resources.store');
        Route::put('/{id}', 'update')->name('resources.update');
        Route::delete('/{id}', 'destroy')->name('resources.destroy');
        Route::delete('/', 'destroyArray')->name('resources.destroyArray');
    });

Route::prefix('venda-chave-troca')
    ->middleware(CheckPermission::class)
    ->controller(VendaChaveTrocaController::class)
    ->group(function () {
        Route::get('/paginated', 'paginated')->name('venda-chave-troca.paginated')->withoutMiddleware([CheckPermission::class]);
        Route::post('/search', 'search')->name('venda-chave-troca.search')->withoutMiddleware([CheckPermission::class]);
        Route::post('/', 'store')->name('venda-chave-troca.store');
        Route::put('/{id}', 'update')->name('venda-chave-troca.update');
        Route::delete('/{id}', 'destroy')->name('venda-chave-troca.destroy');
        Route::delete('/', 'destroyArray')->name('venda-chave-troca.destroyArray');
        Route::get('/when-to-sell', 'whenToSell')->name('venda-chave-troca.when-to-sell')->withoutMiddleware([CheckPermission::class]);
        Route::post('/update-sold-offers', 'updateSoldOffers')->name('venda-chave-troca.update-sold-offers')->withoutMiddleware([CheckPermission::class]);
        Route::get('/search-by-id-gamivo/{idGamivo}', 'searchByIdGamivo')->name('venda-chave-troca.search-by-id-gamivo')->withoutMiddleware([CheckPermission::class]);
        Route::post('/insert-data-venda', 'insertDataVenda')->name('venda-chave-troca.insert-data-venda')->withoutMiddleware([CheckPermission::class]);
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
    return redirect(route('venda-chave-troca', absolute: false));
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', function () {
        return redirect(route('venda-chave-troca', absolute: false));
    })->name('profile.edit');

    Route::patch('/profile', function () {
        return redirect(route('venda-chave-troca', absolute: false));
    })->name('profile.update');

    Route::delete('/profile', function () {
        return redirect(route('venda-chave-troca', absolute: false));
    })->name('profile.destroy');
});

require __DIR__ . '/auth.php';
