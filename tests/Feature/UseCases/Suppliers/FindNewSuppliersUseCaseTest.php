<?php

/*
|--------------------------------------------------------------------------
| FindNewSuppliersUseCase — integration
|--------------------------------------------------------------------------
|
| A varredura em si acontece no price_researcher; aqui só se garante que a
| chamada sai autenticada e que as duas formas de falha viram resultado
| tratável em vez de exceção.
|
*/

use App\UseCases\Suppliers\FindNewSuppliersUseCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('posts to the price_researcher with the external secret', function () {
    config(['services.external_secret' => 'segredo-de-teste']);

    Http::fake(['*/api/suppliers/find-new' => Http::response(['queued' => true], 200)]);

    $result = app(FindNewSuppliersUseCase::class)->execute();

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toBe(['queued' => true]);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer segredo-de-teste'));
});

it('reports the upstream status when the call fails', function () {
    Http::fake(['*/api/suppliers/find-new' => Http::response(['reason' => 'busy'], 503)]);

    $result = app(FindNewSuppliersUseCase::class)->execute();

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(503)
        ->and($result['data'])->toBe(['reason' => 'busy']);
});

it('reports 503 when the price_researcher is unreachable', function () {
    // Serviço fora do ar lança antes de existir resposta: sem tratamento a
    // exceção sobe e o operador recebe um 500 sem explicação.
    Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

    $result = app(FindNewSuppliersUseCase::class)->execute();

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(503);
});
