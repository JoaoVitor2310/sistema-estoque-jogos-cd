<?php

/*
|--------------------------------------------------------------------------
| AuthenticateDeliveryUseCase — orquestração
|--------------------------------------------------------------------------
|
| O que a rota não cobre e vive aqui: o desfecho tipado que o controller usa
| para separar 422 de 429, e o fato de tentativa certa não gastar cota.
|
*/

use App\Domain\Trades\DeliveryCredential;
use App\UseCases\Trades\AuthenticateDeliveryUseCase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\DeliveryFactory;

describe('AuthenticateDeliveryUseCase', function () {

    beforeEach(fn () => RateLimiter::clear('delivery-ip:1.2.3.4'));

    it('reports the right token as accepted', function () {
        [$trade, $token] = DeliveryFactory::tradeWithCredential();

        $result = app(AuthenticateDeliveryUseCase::class)
            ->execute($trade->fresh(), $token, DeliveryFactory::session(), '1.2.3.4');

        expect($result)->toBe(AuthenticateDeliveryUseCase::RESULT_OK);
    });

    it('reports the wrong token as rejected', function () {
        [$trade] = DeliveryFactory::tradeWithCredential();

        $result = app(AuthenticateDeliveryUseCase::class)
            ->execute($trade->fresh(), DeliveryCredential::generate(), DeliveryFactory::session(), '1.2.3.4');

        expect($result)->toBe(AuthenticateDeliveryUseCase::RESULT_WRONG_TOKEN);
    });

    it('reports rate limiting separately from a wrong token', function () {
        // O controller precisa distinguir os dois: 429 e 422 dizem coisas
        // diferentes para quem está do outro lado.
        [$trade] = DeliveryFactory::tradeWithCredential();

        $useCase = app(AuthenticateDeliveryUseCase::class);

        for ($i = 0; $i < DeliveryCredential::MAX_ATTEMPTS_PER_DELIVERY; $i++) {
            $useCase->execute($trade->fresh(), DeliveryCredential::generate(), DeliveryFactory::session(), '1.2.3.4');
        }

        expect($useCase->execute($trade->fresh(), DeliveryCredential::generate(), DeliveryFactory::session(), '1.2.3.4'))
            ->toBe(AuthenticateDeliveryUseCase::RESULT_RATE_LIMITED);
    });

    it('reports no wait while there is still quota', function () {
        [$trade] = DeliveryFactory::tradeWithCredential();

        app(AuthenticateDeliveryUseCase::class)
            ->execute($trade->fresh(), DeliveryCredential::generate(), DeliveryFactory::session(), '1.2.3.4');

        expect(app(AuthenticateDeliveryUseCase::class)->retryAfter($trade, '1.2.3.4'))->toBe(0);
    });

    it('reports how long the block still lasts once it trips', function () {
        // Quem errou o código precisa do número: a janela é de uma hora, e
        // "tente mais tarde" faz o supplier voltar cedo demais.
        [$trade] = DeliveryFactory::tradeWithCredential();

        $useCase = app(AuthenticateDeliveryUseCase::class);

        for ($i = 0; $i < DeliveryCredential::MAX_ATTEMPTS_PER_DELIVERY; $i++) {
            $useCase->execute($trade->fresh(), DeliveryCredential::generate(), DeliveryFactory::session(), '1.2.3.4');
        }

        expect($useCase->retryAfter($trade, '1.2.3.4'))
            ->toBeGreaterThan(0)
            ->toBeLessThanOrEqual(DeliveryCredential::ATTEMPT_WINDOW_MINUTES * 60);
    });

    it('does not spend an attempt when the token is right', function () {
        [$trade, $token] = DeliveryFactory::tradeWithCredential();

        $useCase = app(AuthenticateDeliveryUseCase::class);

        for ($i = 0; $i < DeliveryCredential::MAX_ATTEMPTS_PER_DELIVERY + 5; $i++) {
            expect($useCase->execute($trade->fresh(), $token, DeliveryFactory::session(), '1.2.3.4'))
                ->toBe(AuthenticateDeliveryUseCase::RESULT_OK);
        }
    });
});
