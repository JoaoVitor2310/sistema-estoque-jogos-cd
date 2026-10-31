<?php

use App\Domain\Suppliers\SteamId;
use App\Http\Controllers\Api\TradeController;
use App\Http\Middleware\VerifySecret;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API para outros sistemas
|--------------------------------------------------------------------------
|
| Rotas de máquina, com o prefixo `/api`: sem sessão nem cookie, autenticadas
| pelo Bearer de `VerifySecret` (o mesmo segredo para todo serviço). São rotas
| comuns do Estoque — quem as chama é só um consumidor delas.
|
*/

// Trade com a primeira oferta ao supplier (integração com o chatbot). O
// `from-price-researcher` de web.php segue sendo o das listas e da pesquisa de
// bundle, que não filtram nem ofertam. Ver docs/IMPLEMENTACAO_CHATBOT.md.
Route::post('/trades', [TradeController::class, 'store'])
    ->name('api.trades.store')
    ->middleware(VerifySecret::class);

// Rotas do chatbot. Trade pelo uuid, nunca pelo id sequencial; uuid ou
// SteamID64 malformado é recusado no roteador (404), sem chegar ao banco.
Route::middleware(VerifySecret::class)->group(function () {
    Route::get('/trades/{uuid}', [TradeController::class, 'show'])
        ->whereUuid('uuid')
        ->name('api.trades.show');

    Route::get('/suppliers/{steamId}/trades', [TradeController::class, 'negotiatingForSupplier'])
        ->where('steamId', SteamId::ROUTE_PATTERN)
        ->name('api.suppliers.trades');

    // Escritas do chatbot: a contraproposta (a próxima rodada da oferta) e o
    // registro de que o acesso à entrega foi mandado.
    Route::post('/trades/{uuid}/offers', [TradeController::class, 'counter'])
        ->whereUuid('uuid')
        ->name('api.trades.offers.store');

    Route::post('/trades/{uuid}/message-sent', [TradeController::class, 'markMessageSent'])
        ->whereUuid('uuid')
        ->name('api.trades.message-sent');
});

// O que sobra em /api é 404 em JSON. Sem isto o `Route::fallback` global
// redireciona para /keys: um consumidor de máquina receberia uma página de
// login, e um uuid malformado viraria um 302 em vez de "não encontrado".
Route::any('{path}', fn () => response()->json(['message' => 'Not found.'], 404))
    ->where('path', '.*')
    ->name('api.not-found');
