<?php

/*
|--------------------------------------------------------------------------
| GamivoIdentity — o gamivo_id derivado do par (game_name, region)
|--------------------------------------------------------------------------
|
| A regra pura, sem banco: quando uma escrita invalida o id que a linha
| guarda. O efeito dela na gravação está em
| tests/Feature/UseCases/Trades/UpdateTradeLineUseCaseTest.php.
|
*/

use App\Domain\Trades\GamivoIdentity;

$line = ['game_name' => 'Portal', 'region' => 'EU', 'gamivo_id' => '77'];

describe('GamivoIdentity', function () use ($line) {

    it('invalidates the id when the game name changes', function () use ($line) {
        expect(GamivoIdentity::invalidatedBy($line, ['game_name' => 'Portal 2']))->toBeTrue();
    });

    it('invalidates the id when the region changes', function () use ($line) {
        // O mesmo jogo é outro produto na Gamivo em cada região — é este o caso
        // que passa despercebido no meio da negociação.
        expect(GamivoIdentity::invalidatedBy($line, ['region' => 'BR']))->toBeTrue();
    });

    it('invalidates the id when the region is filled in or emptied', function () use ($line) {
        // Nulo é um valor do par, não a ausência dele: o lookup casou por
        // `region = null`, e preencher a região muda o produto do mesmo jeito.
        expect(GamivoIdentity::invalidatedBy($line, ['region' => null]))->toBeTrue()
            ->and(GamivoIdentity::invalidatedBy(
                ['game_name' => 'Portal', 'region' => null, 'gamivo_id' => '77'],
                ['region' => 'EU'],
            ))->toBeTrue();
    });

    it('keeps the id when the write carries a different one', function () use ($line) {
        // Corrigiu o jogo e já colou o id certo na mesma gravação: apagar
        // desfaria a correção.
        expect(GamivoIdentity::invalidatedBy($line, ['game_name' => 'Portal 2', 'gamivo_id' => '99']))->toBeFalse();
    });

    it('invalidates the id when the write echoes the stored one', function () use ($line) {
        // O caso normal da aba: o autosave manda a linha inteira, então o id
        // guardado volta em toda gravação. Reenviá-lo não é redefini-lo.
        expect(GamivoIdentity::invalidatedBy($line, ['game_name' => 'Portal 2', 'gamivo_id' => '77']))->toBeTrue();
    });

    it('keeps the id when nothing identifying was written', function () use ($line) {
        expect(GamivoIdentity::invalidatedBy($line, ['key_code' => 'AAA-BBB', 'bundle' => 'Humble']))->toBeFalse();
    });

    it('keeps the id when the name is only retouched', function () use ($line) {
        // Caixa e espaço sobrando não trocam de jogo — o lookup do id casa o
        // nome por LOWER(...). Apagar a cada retoque desses seria ruído.
        expect(GamivoIdentity::invalidatedBy($line, ['game_name' => '  portal ']))->toBeFalse()
            ->and(GamivoIdentity::invalidatedBy($line, ['region' => 'eu']))->toBeFalse();
    });

    it('keeps the id when the same value comes back unchanged', function () use ($line) {
        expect(GamivoIdentity::invalidatedBy($line, ['game_name' => 'Portal', 'region' => 'EU']))->toBeFalse();
    });
});
