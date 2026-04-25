<?php

/*
|--------------------------------------------------------------------------
| VerifySecret middleware — characterization tests (6.2)
|--------------------------------------------------------------------------
|
| Verifica que o webhook /vips/callback/{id} só aceita requisições
| que apresentam o Bearer token correto em Authorization.
|
| O price_researcher envia:
|   Authorization: Bearer <EXTERNAL_SECRET>
|
*/

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

describe('VerifySecret middleware', function () {

    // Cria Vip + VipList via DB (VipList não usa HasFactory)
    beforeEach(function () {
        Config::set('services.external_secret', 'test-secret-token-abc123');

        $vipId = DB::table('vips')->insertGetId(['name' => 'Test VIP', 'created_at' => now(), 'updated_at' => now()]);
        $this->vipListId = DB::table('vip_lists')->insertGetId(['vip_id' => $vipId, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
    });

    it('returns 401 when no Authorization header is sent', function () {
        $this->postJson("/vips/callback/{$this->vipListId}", [])
            ->assertStatus(401);
    });

    it('returns 401 when the token is wrong', function () {
        $this->withToken('wrong-token')
            ->postJson("/vips/callback/{$this->vipListId}", [])
            ->assertStatus(401);
    });

    it('returns 401 when the token is empty', function () {
        $this->withToken('')
            ->postJson("/vips/callback/{$this->vipListId}", [])
            ->assertStatus(401);
    });

    it('accepts the request when the correct Bearer token is provided', function () {
        $response = $this->withToken('test-secret-token-abc123')
            ->postJson("/vips/callback/{$this->vipListId}", ['status' => 'completed', 'results' => []]);

        // 200 significa que passou pelo middleware e chegou ao controller
        $response->assertStatus(200);
    });

    it('returns 401 when EXTERNAL_SECRET is not configured', function () {
        Config::set('services.external_secret', null);

        $this->withToken('any-token')
            ->postJson("/vips/callback/{$this->vipListId}", [])
            ->assertStatus(401);
    });
});
