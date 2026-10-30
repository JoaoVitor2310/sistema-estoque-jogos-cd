<?php

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
