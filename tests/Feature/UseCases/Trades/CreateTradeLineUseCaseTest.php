<?php

/*
|--------------------------------------------------------------------------
| CreateTradeLineUseCase — orquestração
|--------------------------------------------------------------------------
|
| O que a rota não cobre e vive aqui: onde a linha nova entra na ordem —
| append, inserção no meio (que empurra as seguintes) e posição além do fim
| (que é aparada em vez de abrir buraco na numeração).
|
*/

use App\Domain\Trades\OverstockPolicy;
use App\UseCases\Trades\CreateTradeLineUseCase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TradeFactory;
use Tests\Support\TradeLineFactory;

describe('CreateTradeLineUseCase — position', function () {

    it('appends to the end when no position is given', function () {
        $trade = TradeFactory::withLines(['A', 'B']);

        $line = app(CreateTradeLineUseCase::class)->execute($trade, TradeLineFactory::dto(['game_name' => 'C']));

        expect($line->position)->toBe(2)
            ->and($trade->fresh()->lines->pluck('game_name')->all())->toBe(['A', 'B', 'C']);
    });

    it('starts at zero on a trade with no lines', function () {
        $trade = TradeFactory::withLines([]);

        expect(app(CreateTradeLineUseCase::class)->execute($trade, TradeLineFactory::dto())->position)->toBe(0);
    });

    it('pushes the following lines down when inserting in the middle', function () {
        $trade = TradeFactory::withLines(['A', 'B', 'C']);

        app(CreateTradeLineUseCase::class)->execute($trade, TradeLineFactory::dto([
            'game_name' => 'A copy',
            'position' => 1,
        ]));

        expect($trade->fresh()->lines->pluck('game_name')->all())
            ->toBe(['A', 'A copy', 'B', 'C'])
            ->and($trade->fresh()->lines->pluck('position')->all())->toBe([0, 1, 2, 3]);
    });

    it('inserting at the front pushes everything down', function () {
        $trade = TradeFactory::withLines(['A', 'B']);

        app(CreateTradeLineUseCase::class)->execute($trade, TradeLineFactory::dto(['game_name' => 'Z', 'position' => 0]));

        expect($trade->fresh()->lines->pluck('game_name')->all())->toBe(['Z', 'A', 'B']);
    });

    it('appends correctly right after a middle insert', function () {
        // Se o empurrão deixasse posição repetida, o append seguinte cairia em
        // cima de uma linha existente.
        $trade = TradeFactory::withLines(['A', 'B']);
        $create = app(CreateTradeLineUseCase::class);

        $create->execute($trade, TradeLineFactory::dto(['game_name' => 'A copy', 'position' => 1]));
        $appended = $create->execute($trade, TradeLineFactory::dto(['game_name' => 'D']));

        expect($appended->position)->toBe(3)
            ->and($trade->fresh()->lines->pluck('game_name')->all())->toBe(['A', 'A copy', 'B', 'D']);
    });

    it('clamps a position past the end instead of leaving a gap', function () {
        // Buraco na numeração quebraria a contiguidade de que a tela depende
        // para espelhar o empurrão do servidor sem reconsultar o banco.
        $trade = TradeFactory::withLines(['A', 'B']);

        $line = app(CreateTradeLineUseCase::class)->execute($trade, TradeLineFactory::dto([
            'game_name' => 'C',
            'position' => 99,
        ]));

        expect($line->position)->toBe(2)
            ->and($trade->fresh()->lines->pluck('position')->all())->toBe([0, 1, 2]);
    });

    it('does not touch the lines of another trade when inserting', function () {
        $other = TradeFactory::withLines(['X', 'Y']);
        $trade = TradeFactory::withLines(['A', 'B']);

        app(CreateTradeLineUseCase::class)->execute($trade, TradeLineFactory::dto(['game_name' => 'New', 'position' => 0]));

        expect($other->fresh()->lines->pluck('position')->all())->toBe([0, 1]);
    });

    it('links the line to the trade it was created on', function () {
        $trade = TradeFactory::withLines([]);

        expect(app(CreateTradeLineUseCase::class)->execute($trade, TradeLineFactory::dto())->trade_id)->toBe($trade->id);
    });

    it('creates a fully blank line when the payload is empty', function () {
        $trade = TradeFactory::withLines([]);

        $line = app(CreateTradeLineUseCase::class)->execute($trade, TradeLineFactory::dto());

        expect($line->game_name)->toBeNull()
            ->and($line->market_price)->toBeNull()
            ->and($line->popularity)->toBeNull()
            ->and($line->key_code)->toBeNull()
            ->and($line->expires_at)->toBeNull();
    });

    it('persists the fields it was given, normalised', function () {
        $trade = TradeFactory::withLines([]);

        $line = app(CreateTradeLineUseCase::class)->execute($trade, TradeLineFactory::dto([
            'game_name' => '  Portal  ',
            'market_price' => '3.00',
            'popularity' => '120',
            'expires_at' => '02/06/2027',
        ]));

        expect($line->game_name)->toBe('Portal')
            ->and($line->market_price)->toBe('3.00')
            ->and($line->popularity)->toBe(120)
            ->and($line->expires_at->format('Y-m-d'))->toBe('2027-06-02');
    });
});

describe('CreateTradeLineUseCase — overstock flag', function () {

    it('flags a line born with an overstocked game, like a duplicate', function () {
        foreach (range(1, OverstockPolicy::MIN_STOCK) as $i) {
            DB::table('keys')->insert([
                'game_name' => 'Portal',
                'key_code' => 'CREATE-STOCK-'.$i,
                'market_price' => 5.00,
                'individual_cost' => 1.00,
                'min_api' => 1.00,
                'max_api' => 10.00,
                'supplier_url' => 'https://steamcommunity.com/id/test',
                'acquired_at' => now()->subDays(OverstockPolicy::MIN_AGE_DAYS + 10)->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $supplierId = DB::table('suppliers')->insertGetId(['url' => 'https://steamcommunity.com/id/create']);
        $trade = TradeFactory::withLines(['Portal'], ['supplier_id' => $supplierId]);

        $line = app(CreateTradeLineUseCase::class)->execute($trade, TradeLineFactory::dto(['game_name' => 'Portal']));

        expect($line->is_overstocked)->toBeTrue();
    });

    it('flags a line of a trade bought on any channel, not only from a supplier', function () {
        foreach (range(1, OverstockPolicy::MIN_STOCK) as $i) {
            DB::table('keys')->insert([
                'game_name' => 'Portal',
                'key_code' => 'CREATE-GAMIVO-'.$i,
                'market_price' => 5.00,
                'individual_cost' => 1.00,
                'min_api' => 1.00,
                'max_api' => 10.00,
                'supplier_url' => 'Gamivo',
                'acquired_at' => now()->subDays(OverstockPolicy::MIN_AGE_DAYS + 10)->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $trade = TradeFactory::withLines(['Portal'], ['purchase_channel' => 'gamivo']);

        $line = app(CreateTradeLineUseCase::class)->execute($trade, TradeLineFactory::dto(['game_name' => 'Portal']));

        expect($line->is_overstocked)->toBeTrue();
    });

    it('does not flag a blank line', function () {
        $supplierId = DB::table('suppliers')->insertGetId(['url' => 'https://steamcommunity.com/id/blank']);
        $trade = TradeFactory::withLines([], ['supplier_id' => $supplierId]);

        $line = app(CreateTradeLineUseCase::class)->execute($trade, TradeLineFactory::dto([]));

        expect($line->refresh()->is_overstocked)->toBeFalse();
    });
});
