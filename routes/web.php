<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuthorizedUsersController;
use App\Http\Controllers\BundleController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\FeeController;
use App\Http\Controllers\Financial\FinancialMonthController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\Keys\KeyController;
use App\Http\Controllers\Keys\KeySaleController;
use App\Http\Controllers\SalesDashboardController;
use App\Http\Controllers\Suppliers\SupplierController;
use App\Http\Controllers\TradeController;
use App\Http\Controllers\TradeLineController;
use App\Http\Middleware\CheckAdmin;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureDeliverySession;
use App\Http\Middleware\RequireTeam;
use App\Http\Middleware\ValidateDeliveryCsrfToken;
use App\Http\Middleware\VerifySecret;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Pages

Route::fallback(function () {
    return redirect()->route('keys');
});

Route::get('/fees', [FeeController::class, 'showMarketPlaceFees'])->name('fees')->middleware(RequireTeam::class);

Route::get('/assets', [AssetController::class, 'show'])->name('assets')->middleware(RequireTeam::class);

Route::get('/bundles', [BundleController::class, 'index'])->name('bundles')->middleware(RequireTeam::class);

// Dashboard analítico de vendas em €. Não confundir com /financial-months, que é
// o livro-caixa dos sócios em R$ — domínios distintos.
Route::get('/sales', [SalesDashboardController::class, 'show'])->name('sales')->middleware(RequireTeam::class);

// Fechamento mensal (FinancialMonth). Página: RequireTeam (login ou 403). Mutações: CheckPermission (403 JSON).
Route::get('/financial-months', [FinancialMonthController::class, 'index'])
    ->name('financial-months')
    ->middleware(RequireTeam::class);

Route::prefix('financial-months')
    ->middleware(CheckPermission::class)
    ->controller(FinancialMonthController::class)
    ->group(function () {
        Route::post('/', 'store')->name('financial-months.store');
        // Um endpoint por tipo de lançamento: cada um valida campos diferentes e
        // os que geram mais de uma linha precisam do próprio UseCase.
        Route::post('/movements', 'storeMovement')->name('financial-months.movements.store');
        Route::post('/transfers', 'storeTransfer')->name('financial-months.transfers.store');
        Route::post('/tf2-allocations', 'storeTf2Allocation')->name('financial-months.tf2-allocations.store');
        Route::post('/partner-distributions', 'storeDistribution')->name('financial-months.partner-distributions.store');
        // Apaga o lançamento inteiro a partir de qualquer perna dele.
        Route::delete('/movements/{financialMovement}', 'destroyMovement')->name('financial-months.movements.destroy');
        Route::post('/close', 'close')->name('financial-months.close');
        Route::post('/{financialMonth}/reopen', 'reopen')->name('financial-months.reopen');
    });

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

// As linhas de uma trade — recurso próprio, mesma permissão das rotas de trade.
// scopeBindings: sem isso, {line} resolveria por id global e daria para alterar
// a linha de outra trade passando qualquer {trade} na URL.
Route::prefix('trades/{trade}/lines')
    ->middleware(CheckPermission::class)
    ->controller(TradeLineController::class)
    ->scopeBindings()
    ->group(function () {
        Route::post('/', 'store')->name('trades.lines.store');
        Route::patch('/{line}', 'update')->name('trades.lines.update');
        Route::delete('/{line}', 'destroy')->name('trades.lines.destroy');
    });

// A entrega de uma trade pelo próprio supplier — ver docs/adr/0008.
//
// **Fora de RequireTeam e de CheckPermission de propósito**: o usuário aqui não
// é a equipe. Quem autoriza é o token da entrega, conferido em `authenticate` e
// exigido pelo EnsureDeliverySession nas escritas.
//
// A trade é resolvida pelo `delivery_uuid` (UUIDv4), nunca pelo id sequencial:
// id entregaria o volume de trades da operação e tornaria a página enumerável.
// scopeBindings pelo mesmo motivo das rotas de linha da equipe — sem ele,
// `{line}` resolveria por id global e a linha de uma trade seria alcançável pela
// URL de outra.
// `whereUuid` recusa no roteador o que não tem forma de UUID: sem ele, a string
// malformada chega ao Postgres como `where delivery_uuid = '...'` e vira 500 com
// stack trace numa rota pública, em vez de 404.
Route::prefix('deliveries/{trade:delivery_uuid}')
    ->controller(DeliveryController::class)
    ->scopeBindings()
    ->whereUuid('trade')
    ->group(function () {
        Route::get('/', 'show')->name('deliveries.show');

        // Fora do EnsureDeliverySession: é o endpoint que *cria* a sessão.
        Route::post('/token', 'authenticate')
            ->middleware(ValidateDeliveryCsrfToken::class)
            ->name('deliveries.token');

        Route::middleware([ValidateDeliveryCsrfToken::class, EnsureDeliverySession::class])
            ->group(function () {
                Route::patch('/', 'updateTrade')->name('deliveries.update');
                Route::patch('/lines/{line}', 'updateLine')->name('deliveries.lines.update');
                Route::post('/deliver', 'deliver')->name('deliveries.deliver');
            });
    });

// O `Route::fallback` global manda toda URL desconhecida para `/keys`. Aqui não:
// quem erra o link da entrega é o supplier, e despejá-lo na aba interna revela
// que ela existe. 404 — o mesmo que ele veria com um uuid bem-formado e
// inexistente. Registrada depois do grupo, então nunca sombreia as rotas reais.
Route::any('deliveries/{path}', fn () => abort(404))->where('path', '.*');

Route::get('/games', [GameController::class, 'index'])->name('games')->middleware(RequireTeam::class);

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

// A vitrine fechou em 2026-08-19. `/keys` e `/bundles` eram as duas páginas que
// respondiam a visitante, e a leitura de keys já saía filtrada por
// `GuestKeyVisibility` — mas quem lê a lista de jogos, com custo e margem, passou
// a incluir os suppliers, que agora conhecem o domínio pela página de entrega.
// A intenção de ter uma página pública continua de pé; ela volta como uma página
// própria de portfólio, escrita para ser vista, e não como a aba interna aberta.
//
// O filtro do controller e a whitelist do `IndexKeysRequest` **ficam onde estão**:
// são a segunda barreira, do mesmo jeito que a guarda de domínio em
// `MarkTradeDeliveredUseCase` é redundante com o middleware da entrega. Se um dia
// alguém tirar o middleware daqui, o `key_code` continua não saindo.
Route::get('/keys', [KeyController::class, 'show'])->name('keys')->middleware(RequireTeam::class);

// Leitura paginada e busca — consumidas por XHR pelo Keys.vue, daí CheckPermission
// (403 JSON) em vez de RequireTeam (redirect): redirect em XHR chega no axios como
// o HTML do login, e o erro que o usuário vê seria de parsing.
Route::get('/keys/paginated', [KeyController::class, 'paginated'])
    ->name('keys.paginated')
    ->middleware(CheckPermission::class);

Route::post('/keys/search', [KeyController::class, 'search'])
    ->name('keys.search')
    ->middleware(CheckPermission::class);

// API externa — Price Researcher
// Autenticado via Bearer token (EXTERNAL_SECRET). Guest: 401. can-edit: não exigido.
Route::post('/suppliers/prospect', [SupplierController::class, 'prospect'])
    ->name('suppliers.prospect')
    ->middleware(VerifySecret::class);

Route::post('/trades/from-price-researcher', [TradeController::class, 'storeFromPriceResearcher'])
    ->name('trades.from-price-researcher')
    ->middleware(VerifySecret::class);

Route::get('/acesso', [AuthorizedUsersController::class, 'index'])->name('acesso')->middleware(RequireTeam::class);

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
        Route::put('/{fee}', 'update')->name('fees.update');
        Route::delete('/{fee}', 'destroy')->name('fees.destroy');
        Route::delete('/', 'destroyArray')->name('fees.destroyArray');
    });

Route::prefix('games')
    ->middleware(CheckPermission::class)
    ->controller(GameController::class)->group(function () {
        Route::get('/paginated', 'paginated')->name('games.paginated');
        Route::post('/search', 'search')->name('games.search');
        Route::post('/', 'store')->name('games.store');
        Route::put('/{game}', 'update')->name('games.update');
        Route::delete('/{game}', 'destroy')->name('games.destroy');
        Route::delete('/', 'destroyArray')->name('games.destroyArray');
    });

Route::prefix('bundles')
    ->middleware(CheckPermission::class)
    ->controller(BundleController::class)->group(function () {
        Route::post('/', 'store')->name('bundles.store');
        Route::put('/{bundle}', 'update')->name('bundles.update');
        Route::delete('/{bundle}', 'destroy')->name('bundles.destroy');
        Route::post('/{bundle}/games', 'addGames')->name('bundles.addGames');
        Route::delete('/{bundle}/games', 'removeGames')->name('bundles.removeGames');
    });

Route::prefix('assets')
    ->middleware(CheckAdmin::class)
    ->controller(AssetController::class)
    ->group(function () {
        Route::post('/', 'store')->name('assets.store');
        Route::put('/{asset}', 'update')->name('assets.update');
        Route::delete('/{asset}', 'destroy')->name('assets.destroy');
        Route::delete('/', 'destroyArray')->name('assets.destroyArray');
    });

Route::prefix('keys')
    ->middleware(CheckPermission::class)
    ->group(function () {
        // KeyController — edição/remoção (mutações exigem permissão).
        // Não há rota de criação: keys entram só via POST /trades/{trade}/import.
        Route::put('/{key}', [KeyController::class, 'update'])->name('keys.update');
        Route::delete('/{key}', [KeyController::class, 'destroy'])->name('keys.destroy');
        Route::delete('/', [KeyController::class, 'destroyArray'])->name('keys.destroyArray');

        // KeySaleController — operações de venda
        Route::get('/auto-sell', [KeySaleController::class, 'autoSell'])->name('keys.auto-sell')->withoutMiddleware([CheckPermission::class])->middleware(VerifySecret::class);
    });

Route::prefix('authorize') // Gerenciar quem tem acesso
    ->middleware(CheckAdmin::class) // Somente o admin poderá acessar essas rotas
    ->controller(AuthorizedUsersController::class)->group(function () {
        Route::post('/', 'store')->name('authorize.store');
        Route::put('/{authorizedUser}', 'update')->name('authorize.update');
        Route::delete('/{authorizedUser}', 'destroy')->name('authorize.destroy');
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
