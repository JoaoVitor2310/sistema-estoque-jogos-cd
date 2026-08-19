<?php

/*
|--------------------------------------------------------------------------
| TradeDeliveryStateTest — o estado derivado da entrega
|--------------------------------------------------------------------------
|
| Casos testados:
|
|   1. sem entrega marcada                        → negotiating
|   2. entregue, não importada                    → awaiting_review
|   3. importada                                  → imported
|   4. importada vence sobre entregue (precedência)
|   5. importada sem entrega marcada ainda é imported
|
|   Janela de escrita do supplier:
|   6. só negotiating aceita escrita dele
|   7. entregue e importada recusam
|
*/

use App\Domain\Enums\TradeDeliveryState;

describe('TradeDeliveryState::resolve', function () {

    it('reads a trade nobody delivered yet as still being negotiated', function () {
        // Toda trade nasce com credencial, então ter link não distingue nada:
        // o que separa é o supplier ter apertado o botão de entregar.
        expect(TradeDeliveryState::resolve(null, false))
            ->toBe(TradeDeliveryState::Negotiating);
    });

    it('reads a delivered, not yet imported trade as the review queue', function () {
        expect(TradeDeliveryState::resolve(new DateTimeImmutable('2026-08-16 10:00:00'), false))
            ->toBe(TradeDeliveryState::AwaitingReview);
    });

    it('reads an imported trade as done', function () {
        expect(TradeDeliveryState::resolve(new DateTimeImmutable('2026-08-16 10:00:00'), true))
            ->toBe(TradeDeliveryState::Imported);
    });

    it('lets the import win over the delivery mark', function () {
        // Sem essa precedência a trade importada ficaria na fila de conferência
        // para sempre: ela continua tendo `delivered_at` preenchido.
        expect(TradeDeliveryState::resolve(new DateTimeImmutable('2026-08-16 10:00:00'), true))
            ->not->toBe(TradeDeliveryState::AwaitingReview);
    });

    it('reads a trade imported without a delivery as done, not as negotiating', function () {
        // O caminho de sempre: a equipe transcreveu as keys do chat e importou,
        // sem o supplier ter tocado na página.
        expect(TradeDeliveryState::resolve(null, true))
            ->toBe(TradeDeliveryState::Imported);
    });
});

describe('TradeDeliveryState::acceptsSupplierWrites', function () {

    it('lets the supplier write only while the trade is still being negotiated', function () {
        expect(TradeDeliveryState::Negotiating->acceptsSupplierWrites())->toBeTrue();
    });

    it('closes the page once the delivery was sent', function () {
        // A janela entre o clique dele e o import da equipe dura dias, e nela as
        // keys entregues são a única cópia que existe — quem entregou não as
        // apaga (ver docs/adr/0008).
        expect(TradeDeliveryState::AwaitingReview->acceptsSupplierWrites())->toBeFalse();
    });

    it('keeps it closed after the import', function () {
        expect(TradeDeliveryState::Imported->acceptsSupplierWrites())->toBeFalse();
    });
});
