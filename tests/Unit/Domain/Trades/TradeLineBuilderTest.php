<?php

use App\Domain\Trades\TradeLineBuilder;

describe('TradeLineBuilder::fromResearch', function () {

    it('converts a researched game into trade line attributes', function () {
        $lines = TradeLineBuilder::fromResearch(
            [['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => 'EU']],
            [],
        );

        expect($lines[0])->toBe([
            'position' => 0,
            'game_name' => 'Half-Life',
            'market_price' => '4.50',
            'popularity' => 500,
            'region' => 'EU',
            'bundle' => null,
            'expires_at' => null,
            'key_code' => null,
            'gamivo_id' => null,
        ]);
    });

    it('numbers the positions in payload order', function () {
        $lines = TradeLineBuilder::fromResearch(
            [
                ['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => null],
                ['name' => 'Portal', 'price_euro' => 2.00, 'popularity' => 100, 'region' => null],
                ['name' => 'Left 4 Dead', 'price_euro' => 3.00, 'popularity' => 200, 'region' => null],
            ],
            [],
        );

        expect(array_column($lines, 'position'))->toBe([0, 1, 2]);
    });

    it('formats price with 2 decimal places', function () {
        $lines = TradeLineBuilder::fromResearch(
            [['name' => 'Portal', 'price_euro' => 10.0, 'popularity' => 100, 'region' => null]],
            [],
        );

        expect($lines[0]['market_price'])->toBe('10.00');
    });

    it('keeps null region as null', function () {
        $lines = TradeLineBuilder::fromResearch(
            [['name' => 'Portal', 'price_euro' => 3.00, 'popularity' => 100, 'region' => null]],
            [],
        );

        expect($lines[0]['region'])->toBeNull();
    });

    it('treats a missing region key as null', function () {
        $lines = TradeLineBuilder::fromResearch(
            [['name' => 'Portal', 'price_euro' => 3.00, 'popularity' => 100]],
            [],
        );

        expect($lines[0]['region'])->toBeNull();
    });

    it('reads popularity as an integer', function () {
        $lines = TradeLineBuilder::fromResearch(
            [['name' => 'Portal', 'price_euro' => 3.00, 'popularity' => '500', 'region' => null]],
            [],
        );

        expect($lines[0]['popularity'])->toBe(500);
    });

    it('carries gamivo_id through', function () {
        $lines = TradeLineBuilder::fromResearch(
            [['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => null, 'gamivo_id' => '144601']],
            [],
        );

        expect($lines[0]['gamivo_id'])->toBe('144601');
    });

    it('leaves gamivo_id null when the researcher did not resolve one', function () {
        $lines = TradeLineBuilder::fromResearch(
            [['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => null]],
            [],
        );

        expect($lines[0]['gamivo_id'])->toBeNull();
    });

    it('fills bundle from the map, matching by normalized name', function () {
        // O mapa vem com a chave já normalizada; o nome pesquisado não.
        $lines = TradeLineBuilder::fromResearch(
            [['name' => 'The Witcher III', 'price_euro' => 4.00, 'popularity' => 800, 'region' => null]],
            ['witcher 3' => 'RPG Bundle'],
        );

        expect($lines[0]['bundle'])->toBe('RPG Bundle');
    });

    it('leaves bundle null when the map has no entry for the game', function () {
        $lines = TradeLineBuilder::fromResearch(
            [['name' => 'Unknown Game', 'price_euro' => 4.00, 'popularity' => 800, 'region' => null]],
            ['witcher 3' => 'RPG Bundle'],
        );

        expect($lines[0]['bundle'])->toBeNull();
    });

    it('leaves every bundle null when the caller resolves no bundle at all', function () {
        $lines = TradeLineBuilder::fromResearch(
            [
                ['name' => 'The Witcher III', 'price_euro' => 4.00, 'popularity' => 800, 'region' => null],
                ['name' => 'Portal', 'price_euro' => 3.00, 'popularity' => 100, 'region' => null],
            ],
            [],
        );

        expect(array_column($lines, 'bundle'))->toBe([null, null]);
    });

    it('resolves bundle independently per game', function () {
        $lines = TradeLineBuilder::fromResearch(
            [
                ['name' => 'Hollow Knight', 'price_euro' => 4.00, 'popularity' => 800, 'region' => null],
                ['name' => 'Unknown Game', 'price_euro' => 6.00, 'popularity' => 200, 'region' => null],
            ],
            ['hollow knight' => 'Indie Bundle'],
        );

        expect($lines[0]['bundle'])->toBe('Indie Bundle')
            ->and($lines[1]['bundle'])->toBeNull();
    });

    it('starts every line without key code and without expiry', function () {
        $lines = TradeLineBuilder::fromResearch(
            [['name' => 'Half-Life', 'price_euro' => 4.50, 'popularity' => 500, 'region' => 'EU']],
            [],
        );

        expect($lines[0]['key_code'])->toBeNull()
            ->and($lines[0]['expires_at'])->toBeNull();
    });

    it('returns an empty list for an empty payload', function () {
        expect(TradeLineBuilder::fromResearch([], []))->toBe([]);
    });
});
