<?php

/*
|--------------------------------------------------------------------------
| UpdateTradeLineUseCase — orquestração
|--------------------------------------------------------------------------
|
| O que a rota não cobre e vive aqui: a semântica de patch parcial (só o que
| o payload trouxe muda) e o escopo por autoridade — o filtro que impede o
| supplier de alcançar coluna que não é dele acontece aqui, não no Request. E a
| limpeza do `gamivo_id` quando o par jogo+região muda, que vale para as duas
| autoridades (a regra pura está em tests/Unit/Domain/Trades/GamivoIdentityTest).
|
*/

use App\Domain\Enums\TradeLineAuthority;
use App\Domain\Trades\OverstockPolicy;
use App\Models\Trade;
use App\UseCases\Trades\UpdateTradeLineUseCase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TradeFactory;
use Tests\Support\TradeLineFactory;

describe('UpdateTradeLineUseCase — partial patch', function () {

    it('changes only the columns the payload carried', function () {
        $trade = TradeFactory::withLines([
            ['game_name' => 'Portal', 'market_price' => '3.00', 'region' => 'EU', 'popularity' => 500],
        ]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($line, TradeLineFactory::dto(['key_code' => 'AAA-BBB']), TradeLineAuthority::Team);

        $line->refresh();

        expect($line->key_code)->toBe('AAA-BBB')
            ->and($line->game_name)->toBe('Portal')
            ->and($line->market_price)->toBe('3.00')
            ->and($line->region)->toBe('EU')
            ->and($line->popularity)->toBe(500);
    });

    it('clears a column that was sent empty', function () {
        $trade = TradeFactory::withLines([['game_name' => 'Portal', 'region' => 'EU']]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($line, TradeLineFactory::dto(['region' => '']), TradeLineAuthority::Team);

        expect($line->refresh()->region)->toBeNull();
    });

    it('leaves the line untouched when the payload carries nothing', function () {
        $trade = TradeFactory::withLines([['game_name' => 'Portal', 'region' => 'EU']]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($line, TradeLineFactory::dto(), TradeLineAuthority::Team);

        $line->refresh();

        expect($line->game_name)->toBe('Portal')
            ->and($line->region)->toBe('EU');
    });

    it('never moves the line, even when the DTO carries a position', function () {
        // `position` sai fora dos atributos de propósito: reordenar não é
        // edição de campo. A rota já recusa, mas o UseCase não depende disso.
        $trade = TradeFactory::withLines(['A', 'B']);
        $first = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($first, TradeLineFactory::dto(['position' => 1, 'key_code' => 'AAA']), TradeLineAuthority::Team);

        expect($first->refresh()->position)->toBe(0)
            ->and($first->key_code)->toBe('AAA');
    });

    it('does not touch the sibling lines', function () {
        $trade = TradeFactory::withLines(['Portal', 'Half-Life']);

        app(UpdateTradeLineUseCase::class)->execute(
            $trade->lines->first(),
            TradeLineFactory::dto(['game_name' => 'Portal 2']),
            TradeLineAuthority::Team,
        );

        expect($trade->fresh()->lines->pluck('game_name')->all())->toBe(['Portal 2', 'Half-Life']);
    });
});

describe('UpdateTradeLineUseCase — derived gamivo id', function () {

    it('clears the gamivo id when the game name changes', function () {
        // O id endereça um produto da Gamivo, e o produto é o par jogo+região.
        // Mudar o nome no meio da negociação é quase sempre trocar o jogo
        // tradado — e o id antigo entraria na key calado.
        $trade = TradeFactory::withLines([['game_name' => 'Portal', 'region' => 'EU', 'gamivo_id' => '77']]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute(
            $line,
            TradeLineFactory::dto(['game_name' => 'Portal 2', 'gamivo_id' => '77']),
            TradeLineAuthority::Team,
        );

        expect($line->refresh()->gamivo_id)->toBeNull();
    });

    it('clears the gamivo id when the supplier changes the region on his page', function () {
        // A limpeza vale para as duas autoridades: o supplier não escolhe o que
        // vai em `gamivo_id` — ele mexe na região, e o id derivado dela cai
        // junto. É o caso que mais passa despercebido, porque quem corrige a
        // região é ele.
        $trade = TradeFactory::withLines([['game_name' => 'Portal', 'region' => 'EU', 'gamivo_id' => '77']]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute(
            $line,
            TradeLineFactory::dto(['region' => 'BR']),
            TradeLineAuthority::Supplier,
        );

        $line->refresh();

        expect($line->region)->toBe('BR')
            ->and($line->gamivo_id)->toBeNull();
    });

    it('keeps a gamivo id that the same write redefines', function () {
        $trade = TradeFactory::withLines([['game_name' => 'Portal', 'region' => 'EU', 'gamivo_id' => '77']]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute(
            $line,
            TradeLineFactory::dto(['game_name' => 'Portal 2', 'gamivo_id' => '99']),
            TradeLineAuthority::Team,
        );

        expect($line->refresh()->gamivo_id)->toBe('99');
    });

    it('leaves the gamivo id alone when the write touches neither name nor region', function () {
        $trade = TradeFactory::withLines([['game_name' => 'Portal', 'region' => 'EU', 'gamivo_id' => '77']]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute(
            $line,
            TradeLineFactory::dto(['key_code' => 'AAA-BBB', 'gamivo_id' => '77']),
            TradeLineAuthority::Team,
        );

        expect($line->refresh()->gamivo_id)->toBe('77');
    });
});

describe('UpdateTradeLineUseCase — authority scope', function () {

    it('writes the three delivery columns when the supplier is the author', function () {
        $trade = TradeFactory::withLines([['game_name' => 'Portal', 'market_price' => '3.00']]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($line, TradeLineFactory::dto([
            'key_code' => 'AAA-BBB',
            'region' => 'EU',
            'expires_at' => '02/06/2027',
        ]), TradeLineAuthority::Supplier);

        $line->refresh();

        expect($line->key_code)->toBe('AAA-BBB')
            ->and($line->region)->toBe('EU')
            ->and($line->expires_at?->format('Y-m-d'))->toBe('2027-06-02');
    });

    it('drops every column outside the supplier scope, even when the payload carries it', function () {
        // O Form Request da entrega não aceita esses campos, mas o UseCase não
        // depende disso: é aqui que a recusa acontece.
        $trade = TradeFactory::withLines([
            ['game_name' => 'Portal', 'market_price' => '3.00', 'popularity' => 500, 'bundle' => 'Humble', 'gamivo_id' => '77'],
        ]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($line, TradeLineFactory::dto([
            'game_name' => 'Outro jogo',
            'market_price' => '99.00',
            'popularity' => 1,
            'gamivo_id' => '999',
            'key_code' => 'AAA-BBB',
        ]), TradeLineAuthority::Supplier);

        $line->refresh();

        expect($line->key_code)->toBe('AAA-BBB')
            ->and($line->game_name)->toBe('Portal')
            ->and($line->market_price)->toBe('3.00')
            ->and($line->popularity)->toBe(500)
            ->and($line->gamivo_id)->toBe('77');
    });

    it('leaves the line untouched when the supplier sends only columns he cannot reach', function () {
        $trade = TradeFactory::withLines([['game_name' => 'Portal', 'market_price' => '3.00']]);
        $line = $trade->lines->first();

        app(UpdateTradeLineUseCase::class)->execute(
            $line,
            TradeLineFactory::dto(['market_price' => '99.00']),
            TradeLineAuthority::Supplier,
        );

        $line->refresh();

        expect($line->market_price)->toBe('3.00')
            ->and($line->game_name)->toBe('Portal');
    });
});

/** Keys paradas e velhas o bastante para o jogo estar encalhado na região. */
function seedUpdateLineOverstock(string $gameName, ?string $region): void
{
    foreach (range(1, OverstockPolicy::MIN_STOCK) as $i) {
        DB::table('keys')->insert([
            'game_name' => $gameName,
            'region' => $region,
            'key_code' => 'UPD-STOCK-'.uniqid(),
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
}

function supplierTradeWithLine(array $line): Trade
{
    $supplierId = DB::table('suppliers')->insertGetId(['url' => 'https://steamcommunity.com/id/'.uniqid()]);

    return TradeFactory::withLines([$line], ['supplier_id' => $supplierId]);
}

describe('UpdateTradeLineUseCase — overstock flag', function () {

    it('flags the line when the region moves it into an overstocked group', function () {
        seedUpdateLineOverstock('Portal', 'ROW');
        $line = supplierTradeWithLine(['game_name' => 'Portal', 'region' => 'EU'])->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($line, TradeLineFactory::dto(['region' => 'ROW']), TradeLineAuthority::Team);

        expect($line->refresh()->is_overstocked)->toBeTrue();
    });

    it('clears the flag when the region moves the line out of the overstocked group', function () {
        seedUpdateLineOverstock('Portal', 'ROW');
        $line = supplierTradeWithLine(['game_name' => 'Portal', 'region' => 'ROW', 'is_overstocked' => true])->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($line, TradeLineFactory::dto(['region' => 'EU']), TradeLineAuthority::Team);

        expect($line->refresh()->is_overstocked)->toBeFalse();
    });

    it('re-evaluates when the supplier corrects the region on delivery', function () {
        // A região é justamente o campo que o supplier corrige, e ela muda o
        // grupo de estoque da key.
        seedUpdateLineOverstock('Portal', null);
        $line = supplierTradeWithLine(['game_name' => 'Portal', 'region' => 'EU'])->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($line, TradeLineFactory::dto(['region' => '']), TradeLineAuthority::Supplier);

        expect($line->refresh()->is_overstocked)->toBeTrue();
    });

    it('keeps the flag recorded when the write does not change the game', function () {
        // A marca registra o que a regra viu quando a linha nasceu; editar a key
        // não é motivo para reescrevê-la com o estoque de hoje.
        $line = supplierTradeWithLine(['game_name' => 'Portal', 'region' => 'EU', 'is_overstocked' => true])->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($line, TradeLineFactory::dto(['key_code' => 'AAA-BBB', 'region' => 'eu']), TradeLineAuthority::Team);

        expect($line->refresh()->is_overstocked)->toBeTrue();
    });

    it('flags a line of a trade without supplier', function () {
        // A regra vale para qualquer canal de compra: bundle ou Gamivo também
        // repõem estoque que não escoa.
        seedUpdateLineOverstock('Portal', 'ROW');
        $line = TradeFactory::withLines([['game_name' => 'Portal', 'region' => 'EU']])->lines->first();

        app(UpdateTradeLineUseCase::class)->execute($line, TradeLineFactory::dto(['region' => 'ROW']), TradeLineAuthority::Team);

        expect($line->refresh()->is_overstocked)->toBeTrue();
    });
});
