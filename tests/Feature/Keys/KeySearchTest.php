<?php

/*
|--------------------------------------------------------------------------
| KeySearchTest — contrato de POST /keys/search
|--------------------------------------------------------------------------
|
| Cobre o comportamento dos filtros legítimos depois que a montagem da query
| saiu do controller para KeyRepository::paginate(), com a whitelist de
| IndexKeysRequest na fronteira HTTP.
|
| O eixo de segurança (guest não pode filtrar por campo que não enxerga)
| vive em tests/Feature/Security/GuestAccessTest.php — aqui só o caminho
| autenticado.
|
*/

use App\Models\AuthorizedUsers;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function seedSearchKeys(): void
{
    DB::table('suppliers')->insertOrIgnore([
        'id' => 1,
        'url' => 'https://steamcommunity.com/id/alpha',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('keys')->insert([
        [
            'id' => 1,
            'key_code' => 'AAAAA-BBBBB-CCCCC',
            'supplier_id' => 1,
            'supplier_url' => 'https://steamcommunity.com/id/alpha',
            'game_name' => 'Portal Two',
            'identified_platform' => 'Steam',
            'region' => 'EU',
            'gamivo_id' => '111',
            'notes' => 'chave revisada',
            'total_paid' => '2x TF2 Keys / 5',
            'market_price' => 10.00,
            'individual_cost' => 5.00,
            'min_api' => 7.00,
            'max_api' => 40.00,
            'acquired_at' => '2026-01-10',
            'listed_at' => '2026-02-01',
            'sold_at' => null,
            'expires_at' => '2026-12-31',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 2,
            'key_code' => 'DDDDD-EEEEE-FFFFF',
            'supplier_id' => 1,
            'supplier_url' => 'https://steamcommunity.com/id/alpha',
            'game_name' => 'Half Life',
            'identified_platform' => 'Steam',
            'region' => 'ROW',
            'gamivo_id' => null,
            'notes' => null,
            'total_paid' => '7x TF2 Keys / 3',
            'market_price' => 20.00,
            'individual_cost' => 9.00,
            'min_api' => 13.00,
            'max_api' => 70.00,
            'acquired_at' => '2026-03-20',
            'listed_at' => null,
            'sold_at' => '2026-04-05',
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
}

function makeKeySearchEditor(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

beforeEach(function () {
    seedSearchKeys();
    $this->actingAs(makeKeySearchEditor());
});

// ── Formato da resposta ──────────────────────────────────────────────────────

it('keeps the response shape consumed by Keys.vue', function () {
    $response = $this->postJson('/keys/search', [])->assertStatus(200);

    // O front lê res.data.data.games.data e res.data.data.totalGames
    $response->assertJsonStructure([
        'data' => [
            'games' => ['data', 'current_page', 'last_page', 'per_page'],
            'totalGames',
            'pagination' => ['current_page', 'last_page', 'per_page'],
        ],
    ]);

    expect($response->json('data.totalGames'))->toBe(2);
});

it('orders by id desc', function () {
    $ids = collect($this->postJson('/keys/search', [])->json('data.games.data'))
        ->pluck('id')
        ->all();

    expect($ids)->toBe([2, 1]);
});

// ── Busca textual ────────────────────────────────────────────────────────────

it('searches game_name by substring, case-insensitively', function () {
    $response = $this->postJson('/keys/search', ['game_name' => 'portal'])->assertStatus(200);

    expect($response->json('data.totalGames'))->toBe(1);
    expect($response->json('data.games.data.0.game_name'))->toBe('Portal Two');
});

it('searches key_code by substring', function () {
    $response = $this->postJson('/keys/search', ['key_code' => 'DDDDD'])->assertStatus(200);

    expect($response->json('data.totalGames'))->toBe(1);
});

it('searches total_paid by substring, since the column holds a label not a number', function () {
    // total_paid é varchar montado por SalePriceCalculator::tradeCostLabel
    // ("2x TF2 Keys / 5"). Validar como numeric rejeitaria o valor real.
    $response = $this->postJson('/keys/search', ['total_paid' => '7x TF2'])->assertStatus(200);

    expect($response->json('data.totalGames'))->toBe(1);
    expect($response->json('data.games.data.0.id'))->toBe(2);
});

// ── Ranges de data (_from / _to) ─────────────────────────────────────────────

it('filters by acquired_at_from', function () {
    $response = $this->postJson('/keys/search', ['acquired_at_from' => '2026-02-01']);

    expect($response->json('data.totalGames'))->toBe(1);
    expect($response->json('data.games.data.0.id'))->toBe(2);
});

it('filters by acquired_at_to', function () {
    $response = $this->postJson('/keys/search', ['acquired_at_to' => '2026-02-01']);

    expect($response->json('data.totalGames'))->toBe(1);
    expect($response->json('data.games.data.0.id'))->toBe(1);
});

it('combines from and to on the same field', function () {
    $response = $this->postJson('/keys/search', [
        'acquired_at_from' => '2026-01-01',
        'acquired_at_to' => '2026-12-31',
    ]);

    expect($response->json('data.totalGames'))->toBe(2);
});

// ── Presença / ausência (sim / nao) ──────────────────────────────────────────

it('filters listed_at_filled by presence', function () {
    expect($this->postJson('/keys/search', ['listed_at_filled' => 'filled'])->json('data.totalGames'))->toBe(1);
    expect($this->postJson('/keys/search', ['listed_at_filled' => 'empty'])->json('data.totalGames'))->toBe(1);
});

it('filters sold_at_filled by presence', function () {
    expect($this->postJson('/keys/search', ['sold_at_filled' => 'filled'])->json('data.totalGames'))->toBe(1);
    expect($this->postJson('/keys/search', ['sold_at_filled' => 'empty'])->json('data.totalGames'))->toBe(1);
});

it('filters expires_at_filled by presence', function () {
    expect($this->postJson('/keys/search', ['expires_at_filled' => 'filled'])->json('data.totalGames'))->toBe(1);
    expect($this->postJson('/keys/search', ['expires_at_filled' => 'empty'])->json('data.totalGames'))->toBe(1);
});

it('filters by notes_filled', function () {
    expect($this->postJson('/keys/search', ['notes_filled' => 'filled'])->json('data.totalGames'))->toBe(1);
    expect($this->postJson('/keys/search', ['notes_filled' => 'empty'])->json('data.totalGames'))->toBe(1);
});

it('filters by gamivo_id_filled', function () {
    expect($this->postJson('/keys/search', ['gamivo_id_filled' => 'filled'])->json('data.totalGames'))->toBe(1);
    expect($this->postJson('/keys/search', ['gamivo_id_filled' => 'empty'])->json('data.totalGames'))->toBe(1);
});

// ── Compatibilidade com Postgres ────────────────────────────────────────────
//
// Produção é Postgres e os testes rodam SQLite. O SQLite não tem tipagem de
// coluna, então aceita `data != ''` calado; o Postgres estoura
// "invalid input syntax for type date". Como o erro é invisível para a suíte,
// a guarda é inspecionar os bindings da query.

/**
 * @return array<int, array{sql: string, bindings: array<int, mixed>}>
 */
function captureKeyQueries(callable $work): array
{
    $queries = [];

    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, '"keys"') || str_contains($query->sql, 'from `keys`')) {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        }
    });

    $work();

    return $queries;
}

it('never compares a date column to an empty string', function (string $filter) {
    foreach (['filled', 'empty'] as $value) {
        $queries = captureKeyQueries(
            fn () => $this->postJson('/keys/search', [$filter => $value])->assertStatus(200),
        );

        expect($queries)->not->toBeEmpty();

        foreach ($queries as $query) {
            // assertNotContains, não expect()->not->toContain(): o toContain do
            // Pest é variádico, então uma mensagem passada como 2º argumento
            // vira um segundo needle e afrouxa a asserção em silêncio.
            $this->assertNotContains(
                '',
                $query['bindings'],
                "Filtro {$filter}={$value} comparou coluna de data com '' — quebra no Postgres. SQL: {$query['sql']}",
            );
        }
    }
})->with(['listed_at_filled', 'sold_at_filled', 'expires_at_filled']);

it('still treats an empty string as absent on text columns', function () {
    DB::table('keys')->where('id', 1)->update(['notes' => '', 'gamivo_id' => '']);

    // Texto vazio é ausência: a key 1 sai do "preenchido" e entra no "vazio".
    expect($this->postJson('/keys/search', ['notes_filled' => 'filled'])->json('data.totalGames'))->toBe(0);
    expect($this->postJson('/keys/search', ['notes_filled' => 'empty'])->json('data.totalGames'))->toBe(2);
    expect($this->postJson('/keys/search', ['gamivo_id_filled' => 'filled'])->json('data.totalGames'))->toBe(0);
});

// ── Whitelist ────────────────────────────────────────────────────────────────

it('rejects a presence filter value outside the enum', function () {
    $this->postJson('/keys/search', ['listed_at_filled' => 'sim'])->assertStatus(422);
});

it('rejects the bare column name now that presence uses the _filled suffix', function () {
    // `listed_at` sozinho deixou de ser filtro: presença é listed_at_filled,
    // intervalo é listed_at_from/_to.
    $this->postJson('/keys/search', ['listed_at' => 'filled'])->assertStatus(422);
});

it('rejects an unknown column filter instead of blowing up with 500', function () {
    $this->postJson('/keys/search', ['coluna_inventada' => 'x'])
        ->assertStatus(422);
});

it('ignores empty values sent by the form', function () {
    // buildSearchPayload() no Keys.vue manda todos os campos, a maioria vazia.
    $response = $this->postJson('/keys/search', [
        'key_code' => '',
        'game_name' => '',
        'notes' => '',
        'acquired_at_from' => null,
        'claim_type' => [],
    ])->assertStatus(200);

    expect($response->json('data.totalGames'))->toBe(2);
});

it('honours limit and keeps it under the cap', function () {
    expect($this->postJson('/keys/search', ['limit' => 1])->json('data.games.per_page'))->toBe(1);

    $this->postJson('/keys/search', ['limit' => 5000])->assertStatus(422);
});
