<?php

/*
|--------------------------------------------------------------------------
| ImportReadinessPolicyTest — quando uma trade pode virar keys
|--------------------------------------------------------------------------
|
| A regra vive no Domain porque servidor e tela decidem pelo mesmo critério:
| `canImport()` no `Trades.vue` habilita o botão, esta classe recusa o lote.
|
| Cobre cada impedimento isolado, o caso feliz, e o que a regra deliberadamente
| ignora — linha em branco é rascunho normal da aba, não erro.
|
*/

use App\Domain\Enums\TradeImportBlocker;
use App\Domain\Trades\ImportReadinessPolicy;

function line(array $overrides = []): array
{
    return array_merge([
        'game_name' => 'Half-Life',
        'market_price' => '4.50',
        'key_code' => 'AAAAA-BBBBB-CCCCC',
    ], $overrides);
}

describe('ImportReadinessPolicy::isFilled', function () {

    it('treats a line with a game name as filled', function () {
        expect(ImportReadinessPolicy::isFilled('Half-Life', null))->toBeTrue();
    });

    it('treats a line with only a market price as filled', function () {
        expect(ImportReadinessPolicy::isFilled(null, '4.50'))->toBeTrue();
    });

    it('treats a zeroed market price as filled, so the missing price is reported', function () {
        expect(ImportReadinessPolicy::isFilled(null, '0.00'))->toBeTrue();
    });

    it('treats a blank line as not filled', function () {
        expect(ImportReadinessPolicy::isFilled(null, null))->toBeFalse();
    });

    it('treats a whitespace-only game name as not filled', function () {
        expect(ImportReadinessPolicy::isFilled('   ', null))->toBeFalse();
    });
});

describe('ImportReadinessPolicy::blockers', function () {

    it('reports nothing when every filled line is complete', function () {
        expect(ImportReadinessPolicy::blockers([line()], '2.00', 'https://steamcommunity.com/id/seller'))
            ->toBe([]);
    });

    it('blocks a filled line without a key code', function () {
        expect(ImportReadinessPolicy::blockers([line(['key_code' => null])], '2.00', 'https://s.com/id/x'))
            ->toBe([TradeImportBlocker::MissingKeyCode]);
    });

    it('blocks a trade without a TF2 quantity', function () {
        expect(ImportReadinessPolicy::blockers([line()], null, 'https://s.com/id/x'))
            ->toBe([TradeImportBlocker::MissingTf2Quantity]);
    });

    it('blocks a trade whose TF2 quantity is zero', function () {
        expect(ImportReadinessPolicy::blockers([line()], '0.00', 'https://s.com/id/x'))
            ->toBe([TradeImportBlocker::MissingTf2Quantity]);
    });

    it('blocks a trade without a supplier', function () {
        expect(ImportReadinessPolicy::blockers([line()], '2.00', null))
            ->toBe([TradeImportBlocker::MissingSupplierUrl]);
    });

    it('blocks a filled line without a game name', function () {
        expect(ImportReadinessPolicy::blockers([line(['game_name' => null])], '2.00', 'https://s.com/id/x'))
            ->toBe([TradeImportBlocker::MissingGameName]);
    });

    it('blocks a filled line without a market price', function () {
        expect(ImportReadinessPolicy::blockers([line(['market_price' => null])], '2.00', 'https://s.com/id/x'))
            ->toBe([TradeImportBlocker::MissingMarketPrice]);
    });

    it('blocks a filled line whose market price is zero', function () {
        expect(ImportReadinessPolicy::blockers([line(['market_price' => '0.00'])], '2.00', 'https://s.com/id/x'))
            ->toBe([TradeImportBlocker::MissingMarketPrice]);
    });

    it('blocks a trade with no line at all', function () {
        expect(ImportReadinessPolicy::blockers([], '2.00', 'https://s.com/id/x'))
            ->toBe([TradeImportBlocker::NoFilledLine]);
    });

    it('blocks a trade whose lines are all blank', function () {
        $blank = ['game_name' => null, 'market_price' => null, 'key_code' => null];

        expect(ImportReadinessPolicy::blockers([$blank, $blank], '2.00', 'https://s.com/id/x'))
            ->toBe([TradeImportBlocker::NoFilledLine]);
    });

    it('ignores a blank line sitting next to a complete one', function () {
        $blank = ['game_name' => null, 'market_price' => null, 'key_code' => null];

        expect(ImportReadinessPolicy::blockers([line(), $blank], '2.00', 'https://s.com/id/x'))
            ->toBe([]);
    });

    it('reports each impediment once, however many lines carry it', function () {
        $blockers = ImportReadinessPolicy::blockers(
            [line(['key_code' => null]), line(['key_code' => '  '])],
            '2.00',
            'https://s.com/id/x',
        );

        expect($blockers)->toBe([TradeImportBlocker::MissingKeyCode]);
    });

    it('reports every impediment of the trade at once', function () {
        $blockers = ImportReadinessPolicy::blockers([line(['key_code' => null, 'game_name' => null])], null, null);

        expect($blockers)->toBe([
            TradeImportBlocker::MissingGameName,
            TradeImportBlocker::MissingKeyCode,
            TradeImportBlocker::MissingTf2Quantity,
            TradeImportBlocker::MissingSupplierUrl,
        ]);
    });
});
