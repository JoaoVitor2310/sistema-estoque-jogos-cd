<?php

/*
|--------------------------------------------------------------------------
| TradeLineRoutesTest — as três rotas de linha
|--------------------------------------------------------------------------
|
|   GET    /trades/{trade}/lines          as linhas, buscadas ao abrir o card
|   POST   /trades/{trade}/lines          cria (no fim, ou numa posição)
|   PATCH  /trades/{trade}/lines/{line}   patch parcial
|   DELETE /trades/{trade}/lines/{line}   remove e fecha o buraco na ordem
|
| Cobre também o que a aba depende para não corromper dado: escrita parcial
| não zera o resto da linha, e a linha de uma trade não é alcançável pela URL
| de outra.
|
*/

use App\Models\AuthorizedUsers;
use App\Models\TradeLine;
use App\Models\User;
use Tests\Support\TradeFactory;

function lineRouteUser(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

describe('GET /trades/{trade}/lines', function () {

    it('returns the lines of the trade in position order', function () {
        $trade = TradeFactory::withLines(['First', 'Second', 'Third']);

        $response = $this->actingAs(lineRouteUser())
            ->getJson("/trades/{$trade->id}/lines")
            ->assertStatus(200);

        expect(array_column($response->json('lines'), 'game_name'))
            ->toBe(['First', 'Second', 'Third']);
    });

    it('does not return the lines of another trade', function () {
        $mine = TradeFactory::withLines(['Portal']);
        TradeFactory::withLines(['Half-Life']);

        $response = $this->actingAs(lineRouteUser())
            ->getJson("/trades/{$mine->id}/lines")
            ->assertStatus(200);

        expect(array_column($response->json('lines'), 'game_name'))->toBe(['Portal']);
    });

    it('blocks a user without permission', function () {
        $trade = TradeFactory::withLines(['Portal', 'Half-Life']);

        // A rota devolve `key_code`: é a mesma permissão das demais rotas de
        // linha, e não pode ficar mais frouxa por ser só leitura.
        $this->actingAs(User::factory()->create())
            ->getJson("/trades/{$trade->id}/lines")
            ->assertStatus(403);
    });

    it('blocks a guest', function () {
        $trade = TradeFactory::withLines(['Portal']);

        $this->getJson("/trades/{$trade->id}/lines")->assertStatus(403);
    });
});

describe('POST /trades/{trade}/lines', function () {

    it('creates a blank line at the end', function () {
        $trade = TradeFactory::withLines(['Half-Life', 'Portal']);

        $response = $this->actingAs(lineRouteUser())
            ->postJson("/trades/{$trade->id}/lines", [])
            ->assertStatus(201);

        expect($response->json('position'))->toBe(2);

        $line = TradeLine::findOrFail($response->json('id'));
        expect($line->trade_id)->toBe($trade->id)
            ->and($line->game_name)->toBeNull();
    });

    it('creates the first line of an empty trade at position zero', function () {
        $trade = TradeFactory::withLines([]);

        $this->actingAs(lineRouteUser())
            ->postJson("/trades/{$trade->id}/lines", [])
            ->assertStatus(201)
            ->assertJson(['position' => 0]);
    });

    it('inserts at a position and pushes the following lines down', function () {
        $trade = TradeFactory::withLines(['A', 'B', 'C']);

        $this->actingAs(lineRouteUser())
            ->postJson("/trades/{$trade->id}/lines", ['game_name' => 'A copy', 'position' => 1])
            ->assertStatus(201);

        expect($trade->fresh()->lines->pluck('game_name')->all())
            ->toBe(['A', 'A copy', 'B', 'C'])
            ->and($trade->fresh()->lines->pluck('position')->all())
            ->toBe([0, 1, 2, 3]);
    });

    it('normalises the fields it stores', function () {
        $trade = TradeFactory::withLines([]);

        $response = $this->actingAs(lineRouteUser())
            ->postJson("/trades/{$trade->id}/lines", [
                'game_name' => '  Portal  ',
                'market_price' => '3.00',
                'expires_at' => '02/06/2027',
                'key_code' => '',
            ])
            ->assertStatus(201);

        $line = TradeLine::findOrFail($response->json('id'));

        expect($line->game_name)->toBe('Portal')
            ->and($line->market_price)->toBe('3.00')
            ->and($line->expires_at->format('Y-m-d'))->toBe('2027-06-02')
            ->and($line->key_code)->toBeNull();
    });

    it('rejects a non-numeric market price', function () {
        $trade = TradeFactory::withLines([]);

        $this->actingAs(lineRouteUser())
            ->postJson("/trades/{$trade->id}/lines", ['market_price' => 'grátis'])
            ->assertStatus(422);
    });

    it('rejects a non-integer popularity', function () {
        $trade = TradeFactory::withLines([]);

        $this->actingAs(lineRouteUser())
            ->postJson("/trades/{$trade->id}/lines", ['popularity' => '1.5'])
            ->assertStatus(422);
    });

    it('returns 403 for a guest', function () {
        $trade = TradeFactory::withLines([]);

        $this->postJson("/trades/{$trade->id}/lines", [])->assertStatus(403);
    });
});

describe('PATCH /trades/{trade}/lines/{line}', function () {

    it('updates only the fields it was given', function () {
        $trade = TradeFactory::withLines([
            ['game_name' => 'Portal', 'market_price' => '3.00', 'region' => 'EU', 'popularity' => 500],
        ]);
        $line = $trade->lines->first();

        $this->actingAs(lineRouteUser())
            ->patchJson("/trades/{$trade->id}/lines/{$line->id}", ['key_code' => 'AAA-BBB'])
            ->assertStatus(200);

        $line->refresh();

        expect($line->key_code)->toBe('AAA-BBB')
            ->and($line->game_name)->toBe('Portal')
            ->and($line->market_price)->toBe('3.00')
            ->and($line->region)->toBe('EU')
            ->and($line->popularity)->toBe(500);
    });

    it('clears a field when it is sent empty', function () {
        $trade = TradeFactory::withLines([['game_name' => 'Portal', 'region' => 'EU']]);
        $line = $trade->lines->first();

        $this->actingAs(lineRouteUser())
            ->patchJson("/trades/{$trade->id}/lines/{$line->id}", ['region' => ''])
            ->assertStatus(200);

        expect($line->refresh()->region)->toBeNull();
    });

    it('parses a dd/mm/yyyy expiry into a date', function () {
        $trade = TradeFactory::withLines(['Portal']);
        $line = $trade->lines->first();

        $this->actingAs(lineRouteUser())
            ->patchJson("/trades/{$trade->id}/lines/{$line->id}", ['expires_at' => '25/02/2027'])
            ->assertStatus(200);

        expect($line->refresh()->expires_at->format('Y-m-d'))->toBe('2027-02-25');
    });

    it('accepts a half-typed date without failing the save', function () {
        // O autosave dispara a cada tecla: recusar "02/0" transformaria
        // digitação em erro de gravação.
        $trade = TradeFactory::withLines(['Portal']);
        $line = $trade->lines->first();

        $this->actingAs(lineRouteUser())
            ->patchJson("/trades/{$trade->id}/lines/{$line->id}", ['expires_at' => '02/0'])
            ->assertStatus(200);

        expect($line->refresh()->expires_at)->toBeNull();
    });

    it('accepts every prefix of a popularity being typed', function () {
        // O autosave dispara a cada tecla; todo prefixo de um inteiro também é
        // um inteiro, então o rigor aqui não atrapalha quem digita.
        $trade = TradeFactory::withLines(['Portal']);
        $line = $trade->lines->first();

        foreach (['5', '50', '500'] as $typed) {
            $this->actingAs(lineRouteUser())
                ->patchJson("/trades/{$trade->id}/lines/{$line->id}", ['popularity' => $typed])
                ->assertStatus(200);
        }

        expect($line->refresh()->popularity)->toBe(500);
    });

    it('refuses to reorder through a field patch', function () {
        // `position` é recusada em vez de ignorada: ignorar faria uma tentativa
        // de reordenar por aqui parecer bem-sucedida.
        $trade = TradeFactory::withLines(['A', 'B']);
        $first = $trade->lines->first();

        $this->actingAs(lineRouteUser())
            ->patchJson("/trades/{$trade->id}/lines/{$first->id}", ['position' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('position');

        expect($first->refresh()->position)->toBe(0);
    });

    it('does not touch the other lines of the trade', function () {
        $trade = TradeFactory::withLines(['Portal', 'Half-Life']);
        $line = $trade->lines->first();

        $this->actingAs(lineRouteUser())
            ->patchJson("/trades/{$trade->id}/lines/{$line->id}", ['key_code' => 'AAA'])
            ->assertStatus(200);

        expect($trade->fresh()->lines[1]->game_name)->toBe('Half-Life')
            ->and($trade->fresh()->lines[1]->key_code)->toBeNull();
    });

    it('returns 404 for a line that belongs to another trade', function () {
        $mine = TradeFactory::withLines(['Portal']);
        $theirs = TradeFactory::withLines(['Half-Life']);
        $theirLine = $theirs->lines->first();

        $this->actingAs(lineRouteUser())
            ->patchJson("/trades/{$mine->id}/lines/{$theirLine->id}", ['key_code' => 'STOLEN'])
            ->assertStatus(404);

        expect($theirLine->refresh()->key_code)->toBeNull();
    });

    it('returns 403 for a guest', function () {
        $trade = TradeFactory::withLines(['Portal']);

        $this->patchJson("/trades/{$trade->id}/lines/{$trade->lines->first()->id}", ['key_code' => 'AAA'])
            ->assertStatus(403);
    });
});

describe('DELETE /trades/{trade}/lines/{line}', function () {

    it('removes the line and closes the gap in the order', function () {
        $trade = TradeFactory::withLines(['A', 'B', 'C']);
        $middle = $trade->lines[1];

        $this->actingAs(lineRouteUser())
            ->deleteJson("/trades/{$trade->id}/lines/{$middle->id}")
            ->assertStatus(204);

        expect($trade->fresh()->lines->pluck('game_name')->all())->toBe(['A', 'C'])
            ->and($trade->fresh()->lines->pluck('position')->all())->toBe([0, 1]);
    });

    it('returns 404 for a line that belongs to another trade', function () {
        $mine = TradeFactory::withLines(['Portal']);
        $theirs = TradeFactory::withLines(['Half-Life']);

        $this->actingAs(lineRouteUser())
            ->deleteJson("/trades/{$mine->id}/lines/{$theirs->lines->first()->id}")
            ->assertStatus(404);

        expect($theirs->fresh()->lines)->toHaveCount(1);
    });

    it('returns 403 for a guest', function () {
        $trade = TradeFactory::withLines(['Portal']);

        $this->deleteJson("/trades/{$trade->id}/lines/{$trade->lines->first()->id}")
            ->assertStatus(403);
    });
});
