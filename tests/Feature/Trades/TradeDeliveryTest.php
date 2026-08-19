<?php

/*
|--------------------------------------------------------------------------
| TradeDeliveryTest — a entrega da trade pelo próprio supplier
|--------------------------------------------------------------------------
|
| Casos testados:
|
|   A credencial (uma por trade, criada com ela):
|     1. token guardado encriptado, legível de volta para a equipe
|     2. não existe rota para emitir um segundo código
|
|   Token (supplier):
|     4. token certo abre a sessão
|     5. token errado não abre
|     6. grafia alternativa (minúscula, sem traços) confere
|     7. sessão de uma entrega não vale para outra
|     8. rate limit por IP bloqueia entre entregas diferentes
|     9. rate limit por entrega bloqueia depois do limite
|    9b. o 429 diz quanto falta, no header `Retry-After` e na mensagem
|    9c. uuid malformado é 404 no roteador, não 500 no banco
|
|   Leitura:
|    10. sem sessão a página não devolve dado nenhum
|    11. com sessão devolve as linhas sem preço, popularidade e gamivo_id
|   11b. o bundle sai pré-preenchido, e ele pode corrigi-lo
|    12. depois do import a página responde o estado concluído
|
|   Escrita:
|    13. o supplier grava key_code, region e expires_at
|    14. a validade vai e volta em mm/dd/aaaa, o formato que ele lê
|    15. o supplier não altera game_name nem market_price pela rota da entrega
|    16. escrita sem sessão é recusada
|    17. sessão morre com a credencial que a abriu
|    18. escrita numa trade já importada é recusada
|    19. a linha de outra trade não é alcançável pela URL desta
|    20. tf2_qty e supplier_notes são gravados
|    21. o botão de entregar grava delivered_at e guarda o primeiro clique
|   21b. entregar sem o total de TF2 acertado é recusado
|    22. entregue, a página para de aceitar escrita — linha, trade e um segundo
|        entregar
|    23. entregue, a página continua abrindo, em leitura
|
*/

use App\Domain\Trades\DeliveryCredential;
use App\Models\AuthorizedUsers;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Support\TradeFactory;

function deliveryUser(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

/**
 * Trade com a credencial que ela teria ao nascer; devolve a trade e o token.
 *
 * A factory insere por query builder e não passa pelos UseCases de criação, que
 * são quem grava a credencial — por isso ela é emitida aqui.
 */
function tradeWithDelivery(array $lines = [], array $attrs = []): array
{
    $trade = TradeFactory::withLines($lines, $attrs);

    $credential = DeliveryCredential::issue();
    $trade->forceFill($credential)->save();

    return [$trade->fresh(), $credential['delivery_token']];
}

describe('The credential a trade is born with', function () {

    it('stores the token encrypted and reads it back for the team', function () {
        // Encriptado na coluna, legível pelo cast: o par fica à vista na aba
        // para copiar, e não existe segunda emissão para reconsultá-lo.
        [$trade, $token] = tradeWithDelivery(['Portal']);

        $raw = DB::table('trades')->where('id', $trade->id)->value('delivery_token');

        expect($trade->delivery_uuid)->not->toBeNull()
            ->and($trade->delivery_token)->toBe($token)
            ->and($raw)->not->toContain($token);
    });

    it('has no route for minting a second one', function () {
        // Uma trade, um código. Um segundo par só criaria a dúvida de qual dos
        // dois está colado na conversa da Steam — e a garantia é estrutural:
        // quem escreve nessas colunas é só a criação da trade.
        expect(Route::has('trades.delivery.store'))->toBeFalse();
    });
});

describe('POST /deliveries/{uuid}/token', function () {

    beforeEach(fn () => RateLimiter::clear('delivery-ip:127.0.0.1'));

    it('opens the session for the right token', function () {
        [$trade, $token] = tradeWithDelivery(['Portal']);

        $this->postJson("/deliveries/{$trade->delivery_uuid}/token", ['token' => $token])
            ->assertStatus(200);

        $this->getJson("/deliveries/{$trade->delivery_uuid}")
            ->assertStatus(200);
    });

    it('rejects the wrong token', function () {
        [$trade] = tradeWithDelivery(['Portal']);

        $this->postJson("/deliveries/{$trade->delivery_uuid}/token", [
            'token' => DeliveryCredential::generate(),
        ])->assertStatus(422);
    });

    it('accepts the token in lowercase and without the display dashes', function () {
        [$trade, $token] = tradeWithDelivery(['Portal']);

        $this->postJson("/deliveries/{$trade->delivery_uuid}/token", [
            'token' => strtolower(str_replace('-', '', $token)),
        ])->assertStatus(200);
    });

    it('scopes the session to one delivery', function () {
        [$first, $token] = tradeWithDelivery(['Portal']);
        [$second] = tradeWithDelivery(['Half-Life']);

        $this->postJson("/deliveries/{$first->delivery_uuid}/token", ['token' => $token])
            ->assertStatus(200);

        // Sessão aberta na primeira entrega, escrevendo na segunda.
        $this->patchJson("/deliveries/{$second->delivery_uuid}", ['tf2_qty' => '10'])
            ->assertStatus(403);
    });

    it('blocks by IP as well, across different deliveries', function () {
        // Só por entrega, um atacante distribui as tentativas entre várias
        // trades e nunca esbarra no limite.
        $trades = [];

        for ($i = 0; $i < DeliveryCredential::MAX_ATTEMPTS_PER_IP; $i++) {
            [$trade] = tradeWithDelivery(['Portal']);
            $trades[] = $trade;

            $this->postJson("/deliveries/{$trade->delivery_uuid}/token", [
                'token' => DeliveryCredential::generate(),
            ])->assertStatus(422);
        }

        [$fresh] = tradeWithDelivery(['Portal']);

        $this->postJson("/deliveries/{$fresh->delivery_uuid}/token", [
            'token' => DeliveryCredential::generate(),
        ])->assertStatus(429);
    });

    it('blocks further attempts once the per-delivery limit is spent', function () {
        [$trade] = tradeWithDelivery(['Portal']);

        for ($i = 0; $i < DeliveryCredential::MAX_ATTEMPTS_PER_DELIVERY; $i++) {
            $this->postJson("/deliveries/{$trade->delivery_uuid}/token", [
                'token' => DeliveryCredential::generate(),
            ])->assertStatus(422);
        }

        $this->postJson("/deliveries/{$trade->delivery_uuid}/token", [
            'token' => DeliveryCredential::generate(),
        ])->assertStatus(429);
    });

    it('answers 404 to a uuid that is not even shaped like one', function () {
        // Sem a restrição no roteador a string malformada chegava ao Postgres e
        // virava 500 com stack trace numa rota pública. O caso não é
        // reproduzível pelo banco na suíte — o SQLite aceita qualquer texto na
        // coluna e devolveria 404 de qualquer jeito —, então o que se testa é o
        // roteador: a rota não casa, e nenhuma query chega a ser montada.
        $this->get('/deliveries/1187bd57-2f73-4cbc-9526-efc248ac666ba')->assertStatus(404);
        $this->get('/deliveries/nao-e-uuid')->assertStatus(404);

        $this->postJson('/deliveries/1187bd57-2f73-4cbc-9526-efc248ac666ba/token', [
            'token' => DeliveryCredential::generate(),
        ])->assertStatus(404);
    });

    it('tells how long the wait is, in the header and in the message', function () {
        // Sem o número o supplier volta em cinco minutos, leva 429 de novo e
        // conclui que o link quebrou — a janela é de uma hora.
        [$trade] = tradeWithDelivery(['Portal']);

        for ($i = 0; $i < DeliveryCredential::MAX_ATTEMPTS_PER_DELIVERY; $i++) {
            $this->postJson("/deliveries/{$trade->delivery_uuid}/token", [
                'token' => DeliveryCredential::generate(),
            ]);
        }

        $response = $this->postJson("/deliveries/{$trade->delivery_uuid}/token", [
            'token' => DeliveryCredential::generate(),
        ])->assertStatus(429);

        expect((int) $response->headers->get('Retry-After'))
            ->toBeGreaterThan(0)
            ->toBeLessThanOrEqual(DeliveryCredential::ATTEMPT_WINDOW_MINUTES * 60)
            ->and($response->json('message'))->toMatch('/Try again in \d+ minutes?\./');
    });
});

describe('GET /deliveries/{uuid}', function () {

    beforeEach(fn () => RateLimiter::clear('delivery-ip:127.0.0.1'));

    it('hands out nothing at all without a session', function () {
        [$trade] = tradeWithDelivery([['game_name' => 'Portal', 'market_price' => '3.00']]);

        $response = $this->get("/deliveries/{$trade->delivery_uuid}");

        $response->assertStatus(200);
        expect($response->content())->not->toContain('Portal');
    });

    it('never exposes the researched price of the games', function () {
        [$trade, $token] = tradeWithDelivery([
            ['game_name' => 'Portal', 'market_price' => '3.00', 'popularity' => 987654, 'bundle' => 'Humble', 'gamivo_id' => 'GAMIVO-987'],
        ]);

        $this->postJson("/deliveries/{$trade->delivery_uuid}/token", ['token' => $token]);

        $props = $this->get("/deliveries/{$trade->delivery_uuid}")
            ->viewData('page')['props'];

        $line = $props['trade']['lines'][0];

        expect($line)->toHaveKeys(['id', 'game_name', 'bundle', 'region', 'expires_at', 'key_code'])
            ->and($line)->not->toHaveKey('market_price')
            ->and($line)->not->toHaveKey('popularity')
            ->and($line)->not->toHaveKey('gamivo_id');

        // Nem no envelope inteiro, por qualquer outro caminho.
        // Valores distintivos de propósito: um `500` qualquer apareceria por
        // acaso no uuid da página e o teste passaria a mentir.
        expect(json_encode($props))->not->toContain('3.00')
            ->and(json_encode($props))->not->toContain('987654')
            ->and(json_encode($props))->not->toContain('GAMIVO-987');
    });

    it('hands him the bundle we found and takes his correction back', function () {
        // O bundle é o único campo do pesquisador que ele alcança (decisão de
        // 2026-08-19, ver docs/adr/0008): chega pré-preenchido pela nossa busca,
        // e quem teve a key na mão sabe melhor de onde ela veio — é a origem que
        // costuma explicar o region lock.
        $trade = openDelivery([['game_name' => 'Portal', 'bundle' => 'Humble Choice']]);
        $line = $trade->lines->first();

        $props = $this->get("/deliveries/{$trade->delivery_uuid}")->viewData('page')['props'];

        expect($props['trade']['lines'][0]['bundle'])->toBe('Humble Choice');

        $this->patchJson("/deliveries/{$trade->delivery_uuid}/lines/{$line->id}", [
            'bundle' => 'Fanatical Build your own',
        ])->assertStatus(200);

        expect($line->refresh()->bundle)->toBe('Fanatical Build your own');
    });

    it('does not hand the supplier the route map of the internal system', function () {
        [$trade] = tradeWithDelivery(['Portal']);

        $html = $this->get("/deliveries/{$trade->delivery_uuid}")->content();

        expect($html)->toContain('deliveries.show')
            ->and($html)->not->toContain('financial-months')
            ->and($html)->not->toContain('keys.search')
            ->and($html)->toContain('noindex');
    });

    it('answers the completed state once the trade was imported', function () {
        [$trade, $token] = tradeWithDelivery(['Portal'], ['is_imported' => true]);

        $props = $this->get("/deliveries/{$trade->delivery_uuid}")->viewData('page')['props'];

        expect($props['state'])->toBe('completed')
            ->and($props)->not->toHaveKey('trade');
    });
});

describe('writes made by the supplier', function () {

    beforeEach(fn () => RateLimiter::clear('delivery-ip:127.0.0.1'));

    /** Abre a sessão e devolve a trade. */
    function openDelivery(array $lines = [], array $attrs = []): Trade
    {
        [$trade, $token] = tradeWithDelivery($lines, $attrs);

        test()->postJson("/deliveries/{$trade->delivery_uuid}/token", ['token' => $token])
            ->assertStatus(200);

        return $trade;
    }

    it('writes the three delivery columns of a line', function () {
        $trade = openDelivery([['game_name' => 'Portal', 'market_price' => '3.00']]);
        $line = $trade->lines->first();

        // A validade é lida em `mm/dd/aaaa`: a página dele é em inglês, e a
        // mesma string lida como a equipe a escreve daria 6 de fevereiro.
        $this->patchJson("/deliveries/{$trade->delivery_uuid}/lines/{$line->id}", [
            'key_code' => 'AAA-BBB-CCC',
            'region' => 'EU',
            'expires_at' => '06/02/2027',
        ])->assertStatus(200);

        $line->refresh();

        expect($line->key_code)->toBe('AAA-BBB-CCC')
            ->and($line->region)->toBe('EU')
            ->and($line->expires_at?->format('Y-m-d'))->toBe('2027-06-02');
    });

    it('reads the expiry date back in the same format it accepts', function () {
        // Ida e volta no formato dele: a página relê pela mesma leitura inicial,
        // e um dos dois lados em formato trocado viraria data corrompida no
        // primeiro autosave depois de recarregar.
        $trade = openDelivery([['game_name' => 'Portal']]);
        $line = $trade->lines->first();

        $this->patchJson("/deliveries/{$trade->delivery_uuid}/lines/{$line->id}", [
            'expires_at' => '06/02/2027',
        ])->assertStatus(200);

        $props = $this->get("/deliveries/{$trade->delivery_uuid}")->viewData('page')['props'];

        expect($props['trade']['lines'][0]['expires_at'])->toBe('06/02/2027');
    });

    it('cannot reach the game name or the researched price', function () {
        $trade = openDelivery([['game_name' => 'Portal', 'market_price' => '3.00']]);
        $line = $trade->lines->first();

        $this->patchJson("/deliveries/{$trade->delivery_uuid}/lines/{$line->id}", [
            'game_name' => 'Outro jogo',
            'market_price' => '99.00',
            'key_code' => 'AAA',
        ])->assertStatus(422);

        $line->refresh();

        expect($line->game_name)->toBe('Portal')
            ->and($line->market_price)->toBe('3.00');
    });

    it('expires the session on its own deadline, not the project-wide one', function () {
        // SESSION_LIFETIME neste projeto é de 7 dias, escolhido para a equipe não
        // relogar. A entrega não herda isso.
        $trade = openDelivery([['game_name' => 'Portal']]);

        $this->travel(DeliveryCredential::SESSION_TTL_MINUTES - 1)->minutes();
        $this->patchJson("/deliveries/{$trade->delivery_uuid}", ['tf2_qty' => '10'])
            ->assertStatus(200);

        $this->travel(2)->minutes();
        $this->patchJson("/deliveries/{$trade->delivery_uuid}", ['tf2_qty' => '11'])
            ->assertStatus(403);

        expect($trade->fresh()->tf2_qty)->toBe('10.00');
    });

    it('drops an open session when the credential changes', function () {
        // A sessão é amarrada à credencial com que foi aberta: uma sessão viva
        // nunca sobrevive ao token que a abriu.
        $trade = openDelivery([['game_name' => 'Portal']]);

        $this->patchJson("/deliveries/{$trade->delivery_uuid}", ['tf2_qty' => '10'])
            ->assertStatus(200);

        $trade->fresh()->forceFill(['delivery_token' => DeliveryCredential::generate()])->save();

        $this->patchJson("/deliveries/{$trade->delivery_uuid}", ['tf2_qty' => '11'])
            ->assertStatus(403);

        expect($trade->fresh()->tf2_qty)->toBe('10.00');
    });

    it('refuses to write without a session', function () {
        [$trade] = tradeWithDelivery([['game_name' => 'Portal']]);
        $line = $trade->lines->first();

        $this->patchJson("/deliveries/{$trade->delivery_uuid}/lines/{$line->id}", ['key_code' => 'AAA'])
            ->assertStatus(403);

        expect($line->refresh()->key_code)->toBeNull();
    });

    it('refuses to write once the trade was imported', function () {
        // Sessão aberta antes do import — a credencial morre nele.
        $trade = openDelivery([['game_name' => 'Portal']]);
        $line = $trade->lines->first();

        $trade->update(['is_imported' => true]);

        $this->patchJson("/deliveries/{$trade->delivery_uuid}/lines/{$line->id}", ['key_code' => 'AAA'])
            ->assertStatus(404);

        expect($line->refresh()->key_code)->toBeNull();
    });

    it('cannot reach a line belonging to another trade', function () {
        $trade = openDelivery([['game_name' => 'Portal']]);
        $other = TradeFactory::withLines(['Half-Life']);
        $foreignLine = $other->lines->first();

        $this->patchJson("/deliveries/{$trade->delivery_uuid}/lines/{$foreignLine->id}", ['key_code' => 'AAA'])
            ->assertStatus(404);

        expect($foreignLine->refresh()->key_code)->toBeNull();
    });

    it('writes tf2_qty and the free-text note on the trade', function () {
        $trade = openDelivery([['game_name' => 'Portal']]);

        $this->patchJson("/deliveries/{$trade->delivery_uuid}", [
            'tf2_qty' => '12.5',
            'supplier_notes' => 'Threw in Half-Life as a bonus.',
        ])->assertStatus(200);

        $trade->refresh();

        expect($trade->tf2_qty)->toBe('12.50')
            ->and($trade->supplier_notes)->toBe('Threw in Half-Life as a bonus.');
    });

    it('records the first click on the deliver button and nothing after it', function () {
        $trade = openDelivery([['game_name' => 'Portal']], ['tf2_qty' => '10']);

        $this->postJson("/deliveries/{$trade->delivery_uuid}/deliver")->assertStatus(200);

        $first = $trade->fresh()->delivered_at;
        expect($first)->not->toBeNull();

        // O segundo clique não é aceito: entregar fechou a entrega, e a data
        // continua sendo a do primeiro.
        $this->travel(1)->hours();
        $this->postJson("/deliveries/{$trade->delivery_uuid}/deliver")->assertStatus(409);

        expect($trade->fresh()->delivered_at->toIso8601String())->toBe($first->toIso8601String());
    });

    it('refuses the delivery until the agreed TF2 total is filled in', function () {
        // É o único campo obrigatório da página dele: sem o total acertado o
        // lote chega à fila já bloqueado no import, e recuperá-lo é voltar a
        // transcrever da conversa — o trabalho que esta página elimina.
        $trade = openDelivery([['game_name' => 'Portal']]);

        $this->postJson("/deliveries/{$trade->delivery_uuid}/deliver")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tell us the total TF2 keys we agreed on before submitting.');

        expect($trade->fresh()->delivered_at)->toBeNull();

        // Preenchido, o mesmo clique passa — e a página continua aberta até lá.
        $this->patchJson("/deliveries/{$trade->delivery_uuid}", ['tf2_qty' => '12.5'])
            ->assertStatus(200);

        $this->postJson("/deliveries/{$trade->delivery_uuid}/deliver")->assertStatus(200);

        expect($trade->fresh()->delivered_at)->not->toBeNull();
    });

    it('stops accepting writes once the delivery was sent', function () {
        // O motivo é a janela entre a entrega e o import: ela dura dias, e nela
        // as keys entregues são a única cópia que existe. Quem já entregou não
        // as apaga — correção depois disso é a equipe quem faz, pela aba
        // interna (ver docs/adr/0008).
        $trade = openDelivery([['game_name' => 'Portal']], ['tf2_qty' => '10']);
        $line = $trade->lines->first();

        $this->patchJson("/deliveries/{$trade->delivery_uuid}/lines/{$line->id}", ['key_code' => 'AAA'])
            ->assertStatus(200);

        $this->postJson("/deliveries/{$trade->delivery_uuid}/deliver")->assertStatus(200);

        // 409 e não 403: a sessão dele continua válida, o que acabou foi a
        // janela de escrita.
        $this->patchJson("/deliveries/{$trade->delivery_uuid}/lines/{$line->id}", ['key_code' => ''])
            ->assertStatus(409);

        $this->patchJson("/deliveries/{$trade->delivery_uuid}", ['tf2_qty' => '99'])
            ->assertStatus(409);

        $trade->refresh();

        expect($line->refresh()->key_code)->toBe('AAA')
            ->and($trade->tf2_qty)->not->toBe('99.00');
    });

    it('still opens the page after the delivery, in read-only', function () {
        // Fechada para escrita, não para leitura: ele volta ao link para
        // conferir o que mandou, e um 404 aqui só produziria "o link quebrou".
        $trade = openDelivery([['game_name' => 'Portal']], ['tf2_qty' => '10']);

        $this->postJson("/deliveries/{$trade->delivery_uuid}/deliver")->assertStatus(200);

        $props = $this->get("/deliveries/{$trade->delivery_uuid}")->viewData('page')['props'];

        expect($props['state'])->toBe('open')
            ->and($props['editable'])->toBeFalse()
            ->and($props['trade']['lines'])->toHaveCount(1);
    });
});
