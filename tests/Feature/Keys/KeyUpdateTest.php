<?php

/*
|--------------------------------------------------------------------------
| PUT /keys/{key} — contrato de edição inline
|--------------------------------------------------------------------------
|
| A whitelist vive em StoreGameRequest, na fronteira HTTP: campo fora dela é
| descartado por validated() e a edição some em silêncio — a tela mostra
| "salvo" e o banco não muda. Foi o que acontecia com min_api/max_api, que
| tinham editor na tela de Keys mas não passavam pela validação.
|
| min_api e max_api são editáveis livremente e NÃO se restringem entre si:
| min_api > max_api é estado legítimo do domínio (o AutoSellUseCase trava o
| max_api de key velha no preço de listagem, e o clamp resolve com o piso
| vencendo o teto — ver docs/GAMIVO.md).
|
| A edição de min_api é transitória por natureza: RegulateMinApiUseCase
| reescreve o piso de toda key não vendida às 07:30 (ver docs/adr/0003).
|
| supplier_url é a origem legível da key e nunca fica vazio: URL do supplier,
| nome do bundle (compra direta) ou "Gamivo". Quando esse texto vira Supplier
| é regra do UseCase — tests/Feature/Keys/UpdateKeyUseCaseTest.php.
|
*/

use App\Models\AuthorizedUsers;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// ── Helpers ───────────────────────────────────────────────────────────────────

function keyUpdateUser(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

function seedKeyUpdateFks(): void
{
    DB::table('fees')->insertOrIgnore([
        ['name' => 'gamivo_percent_low', 'preco' => 0.060, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.250, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.080, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.400, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('assets')->insertOrIgnore([
        'id' => 1, 'name' => 'TF2', 'price_euro' => 2.0, 'price_dollar' => 2.2, 'price_brl' => 10.0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('suppliers')->insertOrIgnore([
        'id' => 1, 'url' => 'https://steamcommunity.com/id/seed', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function keyToEdit(array $overrides = []): int
{
    return DB::table('keys')->insertGetId(array_merge([
        'game_name' => 'Portal',
        'key_code' => 'AAAAA-BBBBB-CCCCC',
        'market_price' => 5.00,
        'individual_cost' => 3.50,
        'min_api' => 1.00,
        'max_api' => 10.00,
        'tf2_quantity' => 2.5,
        'supplier_url' => 'https://steamcommunity.com/id/seed',
        'supplier_id' => 1,
        'acquired_at' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * Payload que a tela de Keys manda: a linha inteira (onEdit envia `{...selected}`).
 *
 * @param  array<string, mixed>  $overrides
 */
function editKeyPayload(array $overrides = []): array
{
    return array_merge([
        'game_name' => 'Portal',
        'key_code' => 'AAAAA-BBBBB-CCCCC',
        'market_price' => '5.00',
        'region' => 'EU',
        'acquired_at' => now()->toDateString(),
        'supplier_url' => 'https://steamcommunity.com/id/seed',
    ], $overrides);
}

function editKey(int $id, array $overrides = [])
{
    return test()->actingAs(keyUpdateUser())
        ->putJson("/keys/{$id}", editKeyPayload($overrides));
}

// ── Testes ────────────────────────────────────────────────────────────────────

describe('PUT /keys/{key} — min_api and max_api', function () {

    beforeEach(fn () => seedKeyUpdateFks());

    it('persists both price limits edited on the Keys screen', function () {
        $id = keyToEdit();

        editKey($id, ['min_api' => '4.20', 'max_api' => '18.75'])->assertStatus(200);

        $key = DB::table('keys')->find($id);

        expect((float) $key->min_api)->toBe(4.20)
            ->and((float) $key->max_api)->toBe(18.75);
    });

    it('accepts a min_api above the max_api', function () {
        // Estado legítimo: o clamp resolve com o piso vencendo o teto. Uma validação
        // cruzada aqui recusaria o que o próprio AutoSellUseCase produz em key velha.
        $id = keyToEdit();

        editKey($id, ['min_api' => '30.00', 'max_api' => '10.00'])->assertStatus(200);

        $key = DB::table('keys')->find($id);

        expect((float) $key->min_api)->toBe(30.00)
            ->and((float) $key->max_api)->toBe(10.00);
    });

    it('keeps the stored limits when the payload omits them', function () {
        $id = keyToEdit(['min_api' => 2.00, 'max_api' => 9.00]);

        editKey($id, ['notes' => 'sem tocar nos limites'])->assertStatus(200);

        $key = DB::table('keys')->find($id);

        expect((float) $key->min_api)->toBe(2.00)
            ->and((float) $key->max_api)->toBe(9.00);
    });

    it('rejects a null limit instead of writing it to a NOT NULL column', function () {
        $id = keyToEdit();

        editKey($id, ['min_api' => null])
            ->assertStatus(422)
            ->assertJsonPath('errors.min_api.0', fn (string $m) => $m !== '');

        expect((float) DB::table('keys')->find($id)->min_api)->toBe(1.00);
    });

    it('rejects a zero or negative limit', function () {
        $id = keyToEdit();

        editKey($id, ['max_api' => '0.00'])->assertStatus(422);
        editKey($id, ['min_api' => '-1.00'])->assertStatus(422);

        $key = DB::table('keys')->find($id);

        expect((float) $key->min_api)->toBe(1.00)
            ->and((float) $key->max_api)->toBe(10.00);
    });

    it('rejects more than two decimal places', function () {
        $id = keyToEdit();

        editKey($id, ['min_api' => '4.2345'])->assertStatus(422);

        expect((float) DB::table('keys')->find($id)->min_api)->toBe(1.00);
    });

    it('keeps the edited limits when the market_price change recalculates the batch', function () {
        // Editar market_price refaz o rateio do lote inteiro (docs/adr/0004). O
        // recálculo não pode passar por cima dos limites editados na mesma request.
        $id = keyToEdit();

        editKey($id, ['market_price' => '9.00', 'min_api' => '6.00', 'max_api' => '40.00'])
            ->assertStatus(200);

        $key = DB::table('keys')->find($id);

        expect((float) $key->market_price)->toBe(9.00)
            ->and((float) $key->min_api)->toBe(6.00)
            ->and((float) $key->max_api)->toBe(40.00);
    });
});

describe('PUT /keys/{key} — supplier', function () {

    beforeEach(fn () => seedKeyUpdateFks());

    it('rejects an edit that blanks the source of the key', function () {
        $id = keyToEdit();

        editKey($id, ['supplier_url' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_url');
    });
});
