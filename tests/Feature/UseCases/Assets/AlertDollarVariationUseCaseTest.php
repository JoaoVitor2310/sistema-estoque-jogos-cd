<?php

/*
|--------------------------------------------------------------------------
| AlertDollarVariationUseCase — integration
|--------------------------------------------------------------------------
|
| O preço em real é a âncora: a conversão parte dele e o dólar resultante é
| comparado com o valor guardado no Asset. Passou do limiar, alerta.
|
| A API de câmbio é sempre falsificada — ver CLAUDE.md.
|
*/

use App\Domain\Assets\AssetAlert;
use App\Mail\DollarVariationAlertMail;
use App\UseCases\Assets\AlertDollarVariationUseCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function seedTf2(float $priceBrl, float $priceDollar): void
{
    DB::table('assets')->insert([
        'name' => 'TF2',
        'price_brl' => $priceBrl,
        'price_dollar' => $priceDollar,
        'price_euro' => 2.0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Cotação em que 1 USD = $rate BRL. */
function fakeCurrencyRate(float $rate): void
{
    Http::fake(['*economia.awesomeapi.com.br*' => Http::response([
        'USDBRL' => ['high' => (string) $rate, 'low' => (string) $rate],
        'EURBRL' => ['high' => '6.0', 'low' => '6.0'],
    ], 200)]);
}

beforeEach(fn () => Mail::fake());

it('alerts when the stored dollar price drifted past the threshold', function () {
    // 10 BRL a 5.00 => 2.00 USD, contra 3.00 guardado: variação de 1.00.
    seedTf2(priceBrl: 10.0, priceDollar: 3.0);
    fakeCurrencyRate(5.0);

    expect(app(AlertDollarVariationUseCase::class)->execute())->toBeTrue();

    Mail::assertSent(DollarVariationAlertMail::class, fn ($mail) => $mail->hasTo(config('app.admin_email'))
        && $mail->currentPrices['price_dollar'] === 2.0);
});

it('stays quiet when the drift is under the threshold', function () {
    // 10 BRL a 5.00 => 2.00 USD, contra 2.10 guardado: variação de 0.10.
    seedTf2(priceBrl: 10.0, priceDollar: 2.10);
    fakeCurrencyRate(5.0);

    expect(app(AlertDollarVariationUseCase::class)->execute())->toBeFalse();

    Mail::assertNothingSent();
});

it('alerts when the drift sits exactly on the threshold', function () {
    // O limiar é inclusivo: >= dispara.
    seedTf2(priceBrl: 10.0, priceDollar: 2.0 + AssetAlert::DOLLAR_PRICE_VARIATION_THRESHOLD);
    fakeCurrencyRate(5.0);

    expect(app(AlertDollarVariationUseCase::class)->execute())->toBeTrue();
});

it('alerts when the dollar moved down, not just up', function () {
    // 10 BRL a 5.00 => 2.00 USD, contra 1.50 guardado: variação de 0.50.
    seedTf2(priceBrl: 10.0, priceDollar: 1.50);
    fakeCurrencyRate(5.0);

    expect(app(AlertDollarVariationUseCase::class)->execute())->toBeTrue();
});

it('does nothing when the TF2 asset is not registered', function () {
    Http::fake();

    expect(app(AlertDollarVariationUseCase::class)->execute())->toBeFalse();

    Http::assertNothingSent();
    Mail::assertNothingSent();
});

it('stays quiet when the currency API fails', function () {
    // Regressão: convertCurrency devolve o VALOR DE ENTRADA quando a API cai.
    // Se convertAll repassasse isso, price_dollar viraria o montante em real
    // (12.50) e a comparação contra 2.30 dispararia um alerta com número
    // inventado. Os valores aqui são propositalmente distantes — seedar
    // price_brl == price_dollar mascararia o bug.
    seedTf2(priceBrl: 12.50, priceDollar: 2.30);
    Http::fake(['*economia.awesomeapi.com.br*' => Http::response([], 500)]);

    expect(app(AlertDollarVariationUseCase::class)->execute())->toBeFalse();

    Mail::assertNothingSent();
});
