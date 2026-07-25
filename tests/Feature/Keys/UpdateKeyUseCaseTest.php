<?php

/*
|--------------------------------------------------------------------------
| UpdateKeyUseCase — characterization tests
|--------------------------------------------------------------------------
|
| Cobre as diferenças críticas em relação ao RegisterKeyUseCase:
|   - Editar recalcula custo/lucros do LOTE inteiro, agrupado pela FK trade_id
|     (keys sem trade_id recalculam só a si) — ver docs/adr/0004
|   - tf2_quantity é preservado do banco se ausente no input
|   - Detecção de duplicidade exclui o próprio registro (excludeId)
|   - Recebe a Key (route model binding) e devolve as keys afetadas + mensagem
|
*/

use App\Models\Key;
use App\Models\Trade;
use App\UseCases\Keys\UpdateKeyUseCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedUpdateFks(): void
{
    DB::table('fees')->insert([
        ['name' => 'gamivo_percent_low', 'preco' => 0.060, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low',       'preco' => 0.250, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.080, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high',       'preco' => 0.400, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('assets')->insert([
        ['name' => 'TF2', 'price_euro' => 2.0, 'price_dollar' => 2.2, 'price_brl' => 10.0, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('suppliers')->insert(['id' => 1, 'url' => 'https://steamcommunity.com/id/seed']);
}

/**
 * Insere uma key diretamente no banco e retorna o ID gerado.
 */
function insertKeyForUpdate(array $overrides = []): int
{
    return DB::table('keys')->insertGetId(array_merge([
        'game_name' => 'Original Game',
        'key_code' => 'ORIG-KEY-00001',
        'market_price' => 5.00,
        'individual_cost' => 3.50, // Custo fixado na compra
        'tf2_quantity' => 2.5,
        'purchase_profit_percent' => 25.00,
        'supplier_url' => 'https://steamcommunity.com/id/seed',
        'supplier_id' => 1,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * Input mínimo válido para o execute() do UpdateKeyUseCase.
 */
function makeUpdateInput(array $overrides = []): array
{
    return array_merge([
        'game_name' => 'Updated Game',
        'key_code' => 'ORIG-KEY-00001',
        'supplier_url' => 'https://steamcommunity.com/id/seed',
        'market_price' => 6.00,
        'region' => null,
        'claim_type' => 'Nenhuma',
        'key_format' => 'RK',
        'sell_platform' => 'Gamivo',
        'acquired_at' => now()->toDateString(),
        'gamivo_id' => null,
        'sold_price' => null,
    ], $overrides);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('UpdateKeyUseCase', function () {

    beforeEach(function () {
        seedUpdateFks();
        Cache::flush();
    });

    // ── Recálculo do lote (ADR-0004) ──────────────────────────────────────────

    it('recalculates only the edited key when it has no trade_id', function () {
        // Sem trade_id → sem lote identificável → recalcula só ela. Sozinha, ela
        // carrega o custo total em TF2: tf2_quantity 2.5 × preço TF2 €2.0 = €5.00.
        $id = insertKeyForUpdate(['individual_cost' => 3.50, 'tf2_quantity' => 2.5]);

        app(UpdateKeyUseCase::class)->execute(Key::findOrFail($id), makeUpdateInput(['market_price' => 9.99]));

        $row = DB::table('keys')->where('id', $id)->first();

        expect((float) $row->individual_cost)->toBe(5.00);
    });

    it('recalculates the whole trade — sibling keys too — when one market_price is edited', function () {
        // Lote de 2 keys vinculadas à mesma trade (trade_id), tf2_quantity 2.5 (total da trade).
        // Inicialmente ambas a €5.00 → income 4.45 cada → custo 2.50 cada.
        $trade = Trade::create(['games' => []]);

        $idA = insertKeyForUpdate(['key_code' => 'AAAAA-11111-11111', 'trade_id' => $trade->id, 'market_price' => 5.00, 'tf2_quantity' => 2.5]);
        $idB = insertKeyForUpdate(['key_code' => 'BBBBB-22222-22222', 'trade_id' => $trade->id, 'market_price' => 5.00, 'tf2_quantity' => 2.5]);

        // Corrige o market_price da key A para €10.00 → income A 8.80, B 4.45, somatório 13.25.
        // Rateio: custo A = 5.0 × 8.80/13.25 = 3.32 ; custo B = 5.0 × 4.45/13.25 = 1.68.
        app(UpdateKeyUseCase::class)->execute(Key::findOrFail($idA), makeUpdateInput([
            'key_code' => 'AAAAA-11111-11111',
            'market_price' => 10.00,
        ]));

        $rowA = DB::table('keys')->where('id', $idA)->first();
        $rowB = DB::table('keys')->where('id', $idB)->first();

        // A key editada e a irmã têm o custo redistribuído
        expect((float) $rowA->individual_cost)->toBe(3.32)
            ->and((float) $rowB->individual_cost)->toBe(1.68);

        // O custo total do lote é preservado (= tf2_quantity × preço TF2 = €5.00)
        expect(round((float) $rowA->individual_cost + (float) $rowB->individual_cost, 2))->toBe(5.00);

        // A porcentagem de lucro na compra é recalculada corretamente (o bug original)
        expect((float) $rowA->purchase_profit_percent)->toBe(165.06)
            ->and((float) $rowB->purchase_profit_percent)->toBe(164.88);
    });

    it('does not touch keys from a different trade', function () {
        $tradeA = Trade::create(['games' => []]);
        $tradeB = Trade::create(['games' => []]);

        $idA = insertKeyForUpdate(['key_code' => 'AAAAA-11111-11111', 'trade_id' => $tradeA->id, 'market_price' => 5.00, 'tf2_quantity' => 2.5]);
        $idOther = insertKeyForUpdate(['key_code' => 'OTHER-22222-22222', 'trade_id' => $tradeB->id, 'market_price' => 5.00, 'tf2_quantity' => 2.5, 'individual_cost' => 5.00]);

        app(UpdateKeyUseCase::class)->execute(Key::findOrFail($idA), makeUpdateInput([
            'key_code' => 'AAAAA-11111-11111',
            'market_price' => 10.00,
        ]));

        $rowOther = DB::table('keys')->where('id', $idOther)->first();

        // Key de outra trade permanece intacta
        expect((float) $rowOther->individual_cost)->toBe(5.00);
    });

    it('does not recalculate the formulas when a non-market_price field is edited', function () {
        // individual_cost propositalmente incoerente com a fórmula, para provar
        // que ela não roda quando o market_price não muda.
        $id = insertKeyForUpdate(['market_price' => 5.00, 'individual_cost' => 99.99]);

        app(UpdateKeyUseCase::class)->execute(Key::findOrFail($id), makeUpdateInput([
            'market_price' => 5.00,          // igual ao do banco → sem recálculo
            'game_name' => 'Nome Novo',
        ]));

        $row = DB::table('keys')->where('id', $id)->first();

        expect($row->game_name)->toBe('Nome Novo')            // campo editado persiste
            ->and((float) $row->individual_cost)->toBe(99.99); // fórmula intacta
    });

    it('does not touch sibling keys when a non-market_price field is edited', function () {
        $trade = Trade::create(['games' => []]);

        $idA = insertKeyForUpdate(['key_code' => 'AAAAA-11111-11111', 'trade_id' => $trade->id, 'market_price' => 5.00, 'tf2_quantity' => 2.5]);
        $idB = insertKeyForUpdate(['key_code' => 'BBBBB-22222-22222', 'trade_id' => $trade->id, 'market_price' => 5.00, 'tf2_quantity' => 2.5, 'individual_cost' => 99.99]);

        app(UpdateKeyUseCase::class)->execute(Key::findOrFail($idA), makeUpdateInput([
            'key_code' => 'AAAAA-11111-11111',
            'market_price' => 5.00,          // sem mudança → não recalcula o lote
            'game_name' => 'Nome Novo',
        ]));

        $rowB = DB::table('keys')->where('id', $idB)->first();

        expect((float) $rowB->individual_cost)->toBe(99.99); // irmã intacta
    });

    it('recalculates a sold sibling sale_profit when the trade cost changes', function () {
        // Se uma key do lote já foi vendida, corrigir o custo do lote reflete no lucro de venda dela.
        $trade = Trade::create(['games' => []]);

        $idA = insertKeyForUpdate(['key_code' => 'AAAAA-11111-11111', 'trade_id' => $trade->id, 'market_price' => 5.00, 'tf2_quantity' => 2.5]);
        $idB = insertKeyForUpdate(['key_code' => 'BBBBB-22222-22222', 'trade_id' => $trade->id, 'market_price' => 5.00, 'tf2_quantity' => 2.5, 'sold_price' => 6.00, 'sold_at' => now()]);

        app(UpdateKeyUseCase::class)->execute(Key::findOrFail($idA), makeUpdateInput([
            'key_code' => 'AAAAA-11111-11111',
            'market_price' => 10.00,
        ]));

        $rowB = DB::table('keys')->where('id', $idB)->first();

        // custo B recalculado para 1.68 → sale_profit = 6.00 − 1.68 = 4.32
        expect((float) $rowB->individual_cost)->toBe(1.68)
            ->and((float) $rowB->sale_profit)->toBe(4.32);
    });

    it('calculates sale_profit when a sold_price is provided without changing market_price', function () {
        // sold_price alimenta o lucro de venda mesmo sem mudança de market_price.
        // individual_cost 3.50 fixo; vendida por 6.00 → sale_profit = 6.00 − 3.50 = 2.50.
        $id = insertKeyForUpdate(['market_price' => 5.00, 'individual_cost' => 3.50]);

        app(UpdateKeyUseCase::class)->execute(Key::findOrFail($id), makeUpdateInput([
            'market_price' => 5.00,  // igual ao do banco → sem recálculo do lote
            'sold_price' => 6.00,
        ]));

        $row = DB::table('keys')->where('id', $id)->first();

        expect((float) $row->sold_price)->toBe(6.00)
            ->and((float) $row->sale_profit)->toBe(2.50)
            ->and((float) $row->sale_profit_percent)->toBe(71.43); // 2.50 / 3.50 × 100
    });

    it('preserves tf2_quantity from the database when not provided in the input', function () {
        $id = insertKeyForUpdate(['tf2_quantity' => 2.5]);

        // Input sem tf2_quantity → deve puxar do banco
        $input = makeUpdateInput();
        unset($input['tf2_quantity']);

        app(UpdateKeyUseCase::class)->execute(Key::findOrFail($id), $input);

        $row = DB::table('keys')->where('id', $id)->first();

        expect((float) $row->tf2_quantity)->toBe(2.5);
    });

    // ── Duplicate detection ───────────────────────────────────────────────────

    it('marks is_duplicate=true when another key already has the same key_code', function () {
        // Duas keys distintas — ao atualizar key2 com o código de key1, deve marcar como duplicada
        insertKeyForUpdate(['key_code' => 'EXISTING-CODE-001']);
        $id2 = insertKeyForUpdate(['key_code' => 'DIFFERENT-CODE-002']);

        app(UpdateKeyUseCase::class)->execute(
            Key::findOrFail($id2),
            makeUpdateInput(['key_code' => 'EXISTING-CODE-001'])
        );

        $row = DB::table('keys')->where('id', $id2)->first();

        expect((bool) $row->is_duplicate)->toBeTrue();
    });

    it('does not mark is_duplicate when the key code belongs to the same record being updated (self-reference)', function () {
        // Editar uma key mantendo o mesmo key_code não deve marcá-la como duplicada
        $id = insertKeyForUpdate(['key_code' => 'MY-OWN-CODE-001']);

        app(UpdateKeyUseCase::class)->execute(
            Key::findOrFail($id),
            makeUpdateInput(['key_code' => 'MY-OWN-CODE-001'])
        );

        $row = DB::table('keys')->where('id', $id)->first();

        expect((bool) $row->is_duplicate)->toBeFalsy();
    });

    // ── Platform identification ───────────────────────────────────────────────

    it('identifies the platform from the key format on update', function () {
        $id = insertKeyForUpdate(['key_code' => 'STEAM-11111-XXXXX']);

        app(UpdateKeyUseCase::class)->execute(
            Key::findOrFail($id),
            makeUpdateInput(['key_code' => 'STEAM-11111-XXXXX'])
        );

        $row = DB::table('keys')->where('id', $id)->first();

        expect($row->identified_platform)->toBe('Steam');
    });

    // ── Return value ──────────────────────────────────────────────────────────

    it('returns the affected keys with relationships loaded', function () {
        $id = insertKeyForUpdate();

        $result = app(UpdateKeyUseCase::class)->execute(Key::findOrFail($id), makeUpdateInput());

        expect($result['keys'])->toHaveCount(1);
        expect($result['keys']->first())->toBeInstanceOf(Key::class)
            ->and($result['keys']->first()->relationLoaded('supplier'))->toBeTrue();
    });

    it('returns every recalculated key of the trade when the market_price changes', function () {
        $trade = Trade::create(['games' => []]);
        $idA = insertKeyForUpdate(['key_code' => 'AAAAA-11111-11111', 'trade_id' => $trade->id, 'market_price' => 5.00, 'tf2_quantity' => 2.5]);
        insertKeyForUpdate(['key_code' => 'BBBBB-22222-22222', 'trade_id' => $trade->id, 'market_price' => 5.00, 'tf2_quantity' => 2.5]);

        $result = app(UpdateKeyUseCase::class)->execute(Key::findOrFail($idA), makeUpdateInput([
            'key_code' => 'AAAAA-11111-11111',
            'market_price' => 10.00,
        ]));

        // as duas keys do lote voltam, para a tela atualizar as irmãs
        expect($result['keys'])->toHaveCount(2)
            ->and($result['keys']->pluck('key_code')->all())->toContain('AAAAA-11111-11111', 'BBBBB-22222-22222');
    });

    it('flags in the message when the platform is not identified', function () {
        $id = insertKeyForUpdate(['key_code' => 'UNKNOWNFORMATKEY']);

        $result = app(UpdateKeyUseCase::class)->execute(
            Key::findOrFail($id),
            makeUpdateInput(['key_code' => 'UNKNOWNFORMATKEY'])
        );

        expect($result['message'])->toContain('plataforma não foi identificada');
    });
});
