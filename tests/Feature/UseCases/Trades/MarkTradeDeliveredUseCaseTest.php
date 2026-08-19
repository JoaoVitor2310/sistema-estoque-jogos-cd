<?php

/*
|--------------------------------------------------------------------------
| MarkTradeDeliveredUseCase — orquestração
|--------------------------------------------------------------------------
|
| O que a rota não cobre e vive aqui: o marco é do primeiro clique e nunca
| anda para frente — reenviar não reabre a contagem para quem revisa —, a
| entrega sem o total de TF2 acertado é recusada, e a entrega avisa a equipe
| por e-mail sem que uma falha de envio custe a entrega.
|
*/

use App\Mail\TradeDeliveredMail;
use App\UseCases\Trades\MarkTradeDeliveredUseCase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TradeFactory;

describe('MarkTradeDeliveredUseCase', function () {

    it('records the moment of the first click', function () {
        $trade = TradeFactory::withLines(['Portal'], ['tf2_qty' => '10']);

        app(MarkTradeDeliveredUseCase::class)->execute($trade);

        expect($trade->fresh()->delivered_at)->not->toBeNull();
    });

    it('never moves the mark forward', function () {
        $trade = TradeFactory::withLines(['Portal'], ['tf2_qty' => '10']);

        app(MarkTradeDeliveredUseCase::class)->execute($trade);
        $first = $trade->fresh()->delivered_at;

        $this->travel(3)->hours();
        app(MarkTradeDeliveredUseCase::class)->execute($trade->fresh());

        expect($trade->fresh()->delivered_at->toIso8601String())->toBe($first->toIso8601String());
    });

    it('refuses a delivery that does not say how many TF2 keys were agreed', function () {
        // O número só existe do lado dele. Sem ele a trade chegaria à fila já
        // bloqueada para o import, e a equipe teria de perguntar no chat a quem
        // já considerou o assunto encerrado.
        Mail::fake();

        $trade = TradeFactory::withLines(['Portal']);

        $accepted = app(MarkTradeDeliveredUseCase::class)->execute($trade);

        expect($accepted)->toBeFalse()
            ->and($trade->fresh()->delivered_at)->toBeNull();

        // Recusada não entra na fila, então não há o que avisar.
        Mail::assertNothingSent();
    });

    it('refuses a zeroed total, the same way the import does', function () {
        $trade = TradeFactory::withLines(['Portal'], ['tf2_qty' => '0']);

        expect(app(MarkTradeDeliveredUseCase::class)->execute($trade))->toBeFalse()
            ->and($trade->fresh()->delivered_at)->toBeNull();
    });

    it('alerts the team that the delivery arrived', function () {
        Mail::fake();

        $trade = TradeFactory::withLines([
            ['game_name' => 'Portal', 'key_code' => 'AAA-BBB'],
            ['game_name' => 'Half-Life'],
        ], ['tf2_qty' => '10']);

        app(MarkTradeDeliveredUseCase::class)->execute($trade);

        Mail::assertSent(TradeDeliveredMail::class, function (TradeDeliveredMail $mail) {
            // A contagem é o que diz se vale abrir agora: linha em branco é
            // resposta legítima, e uma entrega 0/10 não é a mesma notícia que 10/10.
            return $mail->hasTo(config('app.admin_email'))
                && $mail->filledLines === 1
                && $mail->totalLines === 2;
        });
    });

    it('never puts a key inside the alert', function () {
        // O e-mail sai do sistema; a key é a coisa de valor que a trade guarda.
        // Quem for conferir abre a aba, onde o acesso é controlado.
        $trade = TradeFactory::withLines([
            ['game_name' => 'Portal', 'key_code' => 'SEGREDO-1234'],
        ])->load('supplier');

        $trade->delivered_at = now();

        $body = (new TradeDeliveredMail($trade, 1, 1))->render();

        expect($body)->not->toContain('SEGREDO-1234')
            ->and($body)->toContain('1 de 1');
    });

    it('spells the call-to-action colour out inline', function () {
        // Cliente de e-mail impõe a própria cor de link: sem a cor no atributo
        // `style` da âncora, o texto sai azul sobre o roxo do botão.
        $trade = TradeFactory::withLines(['Portal'], ['tf2_qty' => '10'])->load('supplier');
        $trade->delivered_at = now();

        $body = (new TradeDeliveredMail($trade, 1, 1))->render();

        expect($body)->toMatch('/<a[^>]+style="[^"]*color:\s*#ffffff[^"]*"/i');
    });

    it('does not alert twice when the button is clicked again', function () {
        Mail::fake();

        $trade = TradeFactory::withLines(['Portal'], ['tf2_qty' => '10']);

        app(MarkTradeDeliveredUseCase::class)->execute($trade);
        app(MarkTradeDeliveredUseCase::class)->execute($trade->fresh());

        Mail::assertSentCount(1);
    });

    it('keeps the delivery even when the alert cannot be sent', function () {
        // Quem está do outro lado é o supplier: ele não tem o que fazer com um
        // erro de SMTP, e o dado dele já está salvo.
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

        $trade = TradeFactory::withLines(['Portal'], ['tf2_qty' => '10']);

        app(MarkTradeDeliveredUseCase::class)->execute($trade);

        expect($trade->fresh()->delivered_at)->not->toBeNull();
    });
});
