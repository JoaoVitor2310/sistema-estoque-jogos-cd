<?php

/*
|--------------------------------------------------------------------------
| GET /trades — feature tests
|--------------------------------------------------------------------------
|
| Cobre o contrato HTTP:
|   - default: só abertas, ordenação date DESC
|   - view=imported / view=all
|   - filtros date_from / date_to / tf2_min / tf2_max
|   - sort=tf2_qty & dir=asc
|   - sort/dir/view fora da whitelist → 422
|   - paginação: per_page default 40, page=2 traz os próximos
|   - canal de compra e bundle da trade
|
| Segurança de guest é coberta em tests/Feature/Security/GuestAccessTest.php
| (bloco "blocks GET /trades" já existente).
|
*/

use App\Domain\Trades\DeliveryCredential;
use App\Models\AuthorizedUsers;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TradeFactory;

// TradeController::show carrega taxas do Gamivo + preço TF2 para popular
// props auxiliares do card. Sem esses seeds a página lança MarketplaceFee
// erro de "Undefined array key gamivo_percent_low".
beforeEach(function () {
    DB::table('fees')->insert([
        ['name' => 'gamivo_percent_low', 'preco' => 0.060, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_low', 'preco' => 0.250, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_percent_high', 'preco' => 0.080, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'gamivo_fixed_high', 'preco' => 0.400, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('assets')->insert([
        ['name' => 'TF2', 'price_euro' => 2.0, 'price_dollar' => 2.2, 'price_brl' => 10.0, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

function makeAuthorizedIndexUser(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

function seedIndexTrade(array $attrs = []): Trade
{
    return Trade::create($attrs);
}

describe('GET /trades — default view', function () {

    it('returns only open trades by default (paginator inside data)', function () {
        $open = seedIndexTrade(['date' => '2025-06-01']);
        seedIndexTrade(['date' => '2025-06-02', 'is_imported' => true]);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Trades')
                ->where('trades.total', 1)
                ->where('trades.data.0.id', $open->id)
                ->where('trades.data.0.is_imported', false)
            );
    });

    it('orders by date DESC with id DESC tiebreaker', function () {
        $older = seedIndexTrade(['date' => '2025-01-01']);
        $newerA = seedIndexTrade(['date' => '2025-03-01']);
        $newerB = seedIndexTrade(['date' => '2025-03-01']);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades')
            ->assertInertia(fn ($page) => $page
                ->where('trades.data.0.id', $newerB->id)
                ->where('trades.data.1.id', $newerA->id)
                ->where('trades.data.2.id', $older->id)
            );
    });

    it('passes the current filters back to the view', function () {
        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades')
            ->assertInertia(fn ($page) => $page
                ->where('filters.view', 'open')
                ->where('filters.sort', 'date')
                ->where('filters.dir', 'desc')
            );
    });
});

describe('GET /trades — view filter', function () {

    it('returns only imported trades when view=imported', function () {
        seedIndexTrade(['date' => '2025-06-01']);
        $imported = seedIndexTrade(['date' => '2025-06-02', 'is_imported' => true]);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?view=imported')
            ->assertInertia(fn ($page) => $page
                ->where('trades.total', 1)
                ->where('trades.data.0.id', $imported->id)
            );
    });

    it('returns both when view=all', function () {
        seedIndexTrade(['date' => '2025-06-01']);
        seedIndexTrade(['date' => '2025-06-02', 'is_imported' => true]);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?view=all')
            ->assertInertia(fn ($page) => $page
                ->where('trades.total', 2)
            );
    });
});

describe('GET /trades — the review queue', function () {

    it('pins delivered trades to the top of the open view', function () {
        // A entrega que chegou tem de saltar aos olhos sem ninguém ir procurar
        // — é o que dispensa a fila ser a view padrão. Ver docs/adr/0008.
        $newer = seedIndexTrade(['date' => '2025-06-10']);
        $delivered = seedIndexTrade(['date' => '2025-01-01']);
        $delivered->forceFill(['delivery_uuid' => (string) Str::uuid(), 'delivered_at' => now()])->save();

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades')
            ->assertInertia(fn ($page) => $page
                ->where('trades.data.0.id', $delivered->id)
                ->where('trades.data.1.id', $newer->id)
            );
    });

    it('does not pin anything outside the open view', function () {
        // Trade importada também tem `delivered_at`; sem o recorte, histórico
        // subiria para o topo de Importadas e de Todas.
        $newer = seedIndexTrade(['date' => '2025-06-10', 'is_imported' => true]);
        $older = seedIndexTrade(['date' => '2025-01-01', 'is_imported' => true]);
        $older->forceFill(['delivery_uuid' => (string) Str::uuid(), 'delivered_at' => now()])->save();

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?view=imported')
            ->assertInertia(fn ($page) => $page->where('trades.data.0.id', $newer->id));
    });

    it('lists only the queue when view=awaiting_review', function () {
        seedIndexTrade(['date' => '2025-06-01']);
        $delivered = seedIndexTrade(['date' => '2025-06-02']);
        $delivered->forceFill(['delivery_uuid' => (string) Str::uuid(), 'delivered_at' => now()])->save();

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?view=awaiting_review')
            ->assertInertia(fn ($page) => $page
                ->where('trades.total', 1)
                ->where('trades.data.0.id', $delivered->id)
            );
    });

    it('reports how many trades are waiting, regardless of the current view', function () {
        $delivered = seedIndexTrade(['date' => '2025-06-02']);
        $delivered->forceFill(['delivery_uuid' => (string) Str::uuid(), 'delivered_at' => now()])->save();
        seedIndexTrade(['date' => '2025-06-03', 'is_imported' => true]);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?view=imported')
            ->assertInertia(fn ($page) => $page->where('awaitingReviewCount', 1));
    });

    it('exposes the derived delivery state', function () {
        $trade = seedIndexTrade(['date' => '2025-06-02']);
        $trade->forceFill(['delivered_at' => now()])->save();

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades')
            ->assertInertia(fn ($page) => $page->where('trades.data.0.delivery_state', 'awaiting_review'));
    });

    it('hands the team the link and the code to copy', function () {
        // Eles ficam à vista na aba desde que a trade nasce: o que se faz com
        // eles é colar no chat da Steam, e emitir sob demanda punha um passo
        // entre decidir mandar e mandar.
        $trade = seedIndexTrade(['date' => '2025-06-02']);
        $credential = DeliveryCredential::issue();
        $trade->forceFill($credential)->save();

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades')
            ->assertInertia(fn ($page) => $page
                ->where('trades.data.0.delivery_token', $credential['delivery_token'])
                ->where('trades.data.0.delivery_url', route('deliveries.show', ['trade' => $trade->fresh()->delivery_uuid]))
            );
    });

    it('still lists the trade when its stored token does not open with the current key', function () {
        // Um dump de outro ambiente, ou a APP_KEY rotacionada: o token de uma
        // trade deixa de abrir. A aba tem de continuar de pé — o link segue
        // válido, e quem some é só o código daquela trade.
        $trade = seedIndexTrade(['date' => '2025-06-02']);
        $trade->forceFill(DeliveryCredential::issue())->save();

        $foreign = new Encrypter(Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc');

        DB::table('trades')->where('id', $trade->id)->update([
            'delivery_token' => $foreign->encryptString('ABCD-EFGH-JKMN-PQRS'),
        ]);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->where('trades.data.0.delivery_token', null)
                ->where('trades.data.0.delivery_url', route('deliveries.show', ['trade' => $trade->fresh()->delivery_uuid]))
            );
    });
});

describe('GET /trades — date range', function () {

    it('filters by date_from and date_to', function () {
        seedIndexTrade(['date' => '2025-04-01']);
        seedIndexTrade(['date' => '2025-06-15']);
        seedIndexTrade(['date' => '2025-08-01']);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?date_from=2025-05-01&date_to=2025-07-01')
            ->assertInertia(fn ($page) => $page
                ->where('trades.total', 1)
            );
    });
});

describe('GET /trades — tf2 range', function () {

    it('filters by tf2_min and tf2_max', function () {
        seedIndexTrade(['date' => '2025-06-01', 'tf2_qty' => 1.0]);
        seedIndexTrade(['date' => '2025-06-02', 'tf2_qty' => 5.0]);
        seedIndexTrade(['date' => '2025-06-03', 'tf2_qty' => 20.0]);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?tf2_min=3&tf2_max=10')
            ->assertInertia(fn ($page) => $page
                ->where('trades.total', 1)
            );
    });
});

describe('GET /trades — text search', function () {

    it('filters by title substring', function () {
        seedIndexTrade(['date' => '2025-06-01', 'title' => 'Steam Summer Deal']);
        seedIndexTrade(['date' => '2025-06-02', 'title' => 'Winter']);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?title_search=summer')
            ->assertInertia(fn ($page) => $page->where('trades.total', 1));
    });

    it('filters by game name substring', function () {
        TradeFactory::withLines(
            [['game_name' => 'Half-Life 2', 'market_price' => '5.00', 'key_code' => 'AAA']],
            ['date' => '2025-06-01'],
        );
        TradeFactory::withLines(
            [['game_name' => 'Cyberpunk', 'market_price' => '10.00', 'key_code' => 'BBB']],
            ['date' => '2025-06-02'],
        );

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?game_search=half')
            ->assertInertia(fn ($page) => $page->where('trades.total', 1));
    });

    it('filters by supplier url substring', function () {
        $supplierA = DB::table('suppliers')->insertGetId([
            'url' => 'https://steamcommunity.com/id/alice',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $supplierB = DB::table('suppliers')->insertGetId([
            'url' => 'https://steamcommunity.com/id/bob',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        seedIndexTrade(['date' => '2025-06-01', 'supplier_id' => $supplierA]);
        seedIndexTrade(['date' => '2025-06-02', 'supplier_id' => $supplierB]);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?supplier_search=alice')
            ->assertInertia(fn ($page) => $page->where('trades.total', 1));
    });
});

describe('GET /trades — sort', function () {

    it('sorts by tf2_qty asc when requested', function () {
        seedIndexTrade(['date' => '2025-06-01', 'tf2_qty' => 10.0]);
        seedIndexTrade(['date' => '2025-06-01', 'tf2_qty' => 2.0]);
        seedIndexTrade(['date' => '2025-06-01', 'tf2_qty' => 5.0]);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?sort=tf2_qty&dir=asc')
            ->assertInertia(fn ($page) => $page
                ->where('trades.data.0.tf2_qty', '2.00')
                ->where('trades.data.1.tf2_qty', '5.00')
                ->where('trades.data.2.tf2_qty', '10.00')
            );
    });
});

describe('GET /trades — validation', function () {

    it('rejects sort outside the whitelist with 422', function () {
        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?sort=title')
            ->assertStatus(302); // Laravel redirects HTML requests on validation failure
    });

    it('returns 422 for invalid sort when the request accepts JSON', function () {
        $this->actingAs(makeAuthorizedIndexUser())
            ->getJson('/trades?sort=drop_table')
            ->assertStatus(422);
    });

    it('returns 422 for invalid view', function () {
        $this->actingAs(makeAuthorizedIndexUser())
            ->getJson('/trades?view=weird')
            ->assertStatus(422);
    });

    it('returns 422 for malformed date', function () {
        $this->actingAs(makeAuthorizedIndexUser())
            ->getJson('/trades?date_from=15/06/2025')
            ->assertStatus(422);
    });
});

describe('GET /trades — pagination', function () {

    it('paginates 40 per page by default and page=2 brings the next batch', function () {
        for ($i = 1; $i <= 45; $i++) {
            seedIndexTrade(['date' => '2025-06-01']);
        }

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades')
            ->assertInertia(fn ($page) => $page
                ->where('trades.total', 45)
                ->where('trades.per_page', 40)
                ->where('trades.current_page', 1)
                ->has('trades.data', 40)
            );

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades?page=2')
            ->assertInertia(fn ($page) => $page
                ->where('trades.current_page', 2)
                ->has('trades.data', 5)
            );
    });
});

describe('GET /trades — purchase channel', function () {

    it('exposes the purchase channel and the bundle of the trade', function () {
        $bundleId = DB::table('bundles')->insertGetId(['name' => 'Humble Choice September', 'created_at' => now(), 'updated_at' => now()]);
        seedIndexTrade(['date' => '2025-06-02', 'purchase_channel' => 'bundle_store', 'bundle_id' => $bundleId]);

        $this->actingAs(makeAuthorizedIndexUser())
            ->get('/trades')
            ->assertInertia(fn ($page) => $page
                ->where('trades.data.0.purchase_channel', 'bundle_store')
                ->where('trades.data.0.bundle_id', $bundleId));
    });
});
