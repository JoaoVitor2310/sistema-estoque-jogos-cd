<?php

use App\Domain\Trades\GamivoIdentity;
use App\Domain\Trades\LineGamePair;

describe('LineGamePair::changedBy', function () {

    $line = ['game_name' => 'Portal', 'region' => 'EU', 'gamivo_id' => '77'];

    it('reports a change of region', function () use ($line) {
        expect(LineGamePair::changedBy($line, ['region' => 'ROW']))->toBeTrue();
    });

    it('reports a change of name', function () use ($line) {
        expect(LineGamePair::changedBy($line, ['game_name' => 'Portal 2']))->toBeTrue();
    });

    it('reports the change even when the write carries a different id', function () use ($line) {
        // A exceção do id redefinido protege só o id. A marca de encalhe
        // também deriva do par e precisa ser refeita mesmo assim.
        expect(LineGamePair::changedBy($line, ['region' => 'ROW', 'gamivo_id' => '99']))->toBeTrue()
            ->and(GamivoIdentity::invalidatedBy($line, ['region' => 'ROW', 'gamivo_id' => '99']))->toBeFalse();
    });

    it('ignores a retouch of case or spacing', function () use ($line) {
        expect(LineGamePair::changedBy($line, ['game_name' => ' portal ', 'region' => 'eu']))->toBeFalse();
    });

    it('ignores writes that touch neither name nor region', function () use ($line) {
        expect(LineGamePair::changedBy($line, ['key_code' => 'AAA-BBB']))->toBeFalse();
    });
});
