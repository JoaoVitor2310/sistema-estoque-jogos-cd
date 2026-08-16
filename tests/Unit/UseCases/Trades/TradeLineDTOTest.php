<?php

use App\UseCases\Trades\DTO\TradeLineDTO;

describe('TradeLineDTO::fromValidated — typing', function () {

    it('carries every field as its own type', function () {
        $dto = TradeLineDTO::fromValidated([
            'game_name' => 'Half-Life',
            'market_price' => '4.50',
            'popularity' => '500',
            'region' => 'EU',
            'bundle' => 'Humble Choice',
            'expires_at' => '02/06/2027',
            'key_code' => 'AAA-BBB',
            'gamivo_id' => '144601',
            'position' => '3',
        ]);

        expect($dto->gameName)->toBe('Half-Life')
            ->and($dto->marketPrice)->toBe('4.50')
            ->and($dto->popularity)->toBe(500)
            ->and($dto->region)->toBe('EU')
            ->and($dto->bundle)->toBe('Humble Choice')
            ->and($dto->expiresAt)->toBe('2027-06-02')
            ->and($dto->keyCode)->toBe('AAA-BBB')
            ->and($dto->gamivoId)->toBe('144601')
            ->and($dto->position)->toBe(3);
    });

    it('keeps position out of the attributes — where the line goes is not a column to write', function () {
        $dto = TradeLineDTO::fromValidated(['game_name' => 'Portal', 'position' => 2]);

        expect($dto->position)->toBe(2)
            ->and($dto->toAttributes())->toBe(['game_name' => 'Portal']);
    });
});

describe('TradeLineDTO::toAttributes — absent is not null', function () {

    it('returns only the columns the payload carried', function () {
        $dto = TradeLineDTO::fromValidated(['key_code' => 'AAA-BBB']);

        expect($dto->toAttributes())->toBe(['key_code' => 'AAA-BBB']);
    });

    it('returns a column sent empty, so it can be cleared', function () {
        // Mandar vazio é um pedido explícito de limpar; não mandar é silêncio.
        $dto = TradeLineDTO::fromValidated(['region' => '']);

        expect($dto->toAttributes())->toBe(['region' => null]);
    });

    it('returns a column sent as null, so it can be cleared', function () {
        $dto = TradeLineDTO::fromValidated(['region' => null]);

        expect($dto->toAttributes())->toBe(['region' => null]);
    });

    it('returns nothing for an empty payload', function () {
        expect(TradeLineDTO::fromValidated([])->toAttributes())->toBe([]);
    });

    it('never invents a column the payload did not carry', function () {
        $attributes = TradeLineDTO::fromValidated(['game_name' => 'Portal'])->toAttributes();

        expect(array_keys($attributes))->toBe(['game_name']);
    });

    it('keeps the columns in a stable order regardless of payload order', function () {
        $attributes = TradeLineDTO::fromValidated([
            'key_code' => 'AAA',
            'game_name' => 'Portal',
        ])->toAttributes();

        expect(array_keys($attributes))->toBe(['game_name', 'key_code']);
    });
});

describe('TradeLineDTO::fromValidated — normalisation', function () {

    it('normalises through the same rules as the backfill', function () {
        $dto = TradeLineDTO::fromValidated([
            'game_name' => '  Portal  ',
            'market_price' => '4,50',
            'popularity' => 'muito',
            'expires_at' => '31/02/2027',
        ]);

        expect($dto->gameName)->toBe('Portal')
            ->and($dto->marketPrice)->toBe('4.50')
            ->and($dto->popularity)->toBeNull()
            ->and($dto->expiresAt)->toBeNull();
    });

    it('still reports a field as provided when normalising turned it null', function () {
        // O campo veio e virou null — quem gravar precisa limpar a coluna, não ignorá-la.
        $dto = TradeLineDTO::fromValidated(['expires_at' => 'junho']);

        expect($dto->toAttributes())->toBe(['expires_at' => null]);
    });
});
