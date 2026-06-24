<?php

/*
|--------------------------------------------------------------------------
| SupplierService — characterization tests
|--------------------------------------------------------------------------
|
| SupplierService é infraestrutura pura: find-or-create de fornecedores
| pela url (URL do perfil Steam).
|
| A idempotência é crítica porque RegisterKeyUseCase chama findOrCreate
| para cada key do lote — múltiplas keys do mesmo fornecedor não podem
| criar registros duplicados.
|
| has_traded:
|   - findOrCreate sempre cria com has_traded = true (fluxo de keys reais)
|   - upsert nunca altera has_traded (fluxo de prospects)
|
*/

use App\Services\Suppliers\SupplierService;
use Illuminate\Support\Facades\DB;

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('SupplierService', function () {

    describe('findOrCreate()', function () {

        it('creates a new supplier when the profile does not exist', function () {
            $profile = 'https://steamcommunity.com/id/brandnew';

            expect(DB::table('suppliers')->where('url', $profile)->exists())->toBeFalse();

            app(SupplierService::class)->findOrCreate($profile);

            expect(DB::table('suppliers')->where('url', $profile)->exists())->toBeTrue();
        });

        it('returns the ID of the newly created supplier', function () {
            $id = app(SupplierService::class)->findOrCreate('https://steamcommunity.com/id/newseller');

            expect($id)->toBeInt()->toBeGreaterThan(0);
        });

        it('returns the existing supplier ID when the profile already exists', function () {
            // Pré-cria o fornecedor
            $existingId = DB::table('suppliers')->insertGetId([
                'url' => 'https://steamcommunity.com/id/existing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $returnedId = app(SupplierService::class)->findOrCreate('https://steamcommunity.com/id/existing');

            expect($returnedId)->toBe($existingId);
        });

        it('is idempotent — two calls with the same profile return the same ID', function () {
            $profile = 'https://steamcommunity.com/id/idempotent';

            $id1 = app(SupplierService::class)->findOrCreate($profile);
            $id2 = app(SupplierService::class)->findOrCreate($profile);

            expect($id1)->toBe($id2);
        });

        it('does not create duplicate suppliers when called multiple times', function () {
            $profile = 'https://steamcommunity.com/id/noduplicate';

            app(SupplierService::class)->findOrCreate($profile);
            app(SupplierService::class)->findOrCreate($profile);
            app(SupplierService::class)->findOrCreate($profile);

            $count = DB::table('suppliers')->where('url', $profile)->count();

            expect($count)->toBe(1);
        });

        it('treats different profiles as different suppliers', function () {
            $id1 = app(SupplierService::class)->findOrCreate('https://steamcommunity.com/id/sellerA');
            $id2 = app(SupplierService::class)->findOrCreate('https://steamcommunity.com/id/sellerB');

            expect($id1)->not->toBe($id2);
        });

        it('sets has_traded = true when creating a new supplier', function () {
            $id = app(SupplierService::class)->findOrCreate('https://steamcommunity.com/id/trader');

            expect((bool) DB::table('suppliers')->where('id', $id)->value('has_traded'))->toBeTrue();
        });

        it('does not overwrite has_traded when supplier already exists', function () {
            // Supplier criado como prospect (has_traded = false)
            DB::table('suppliers')->insert([
                'url' => 'https://steamcommunity.com/id/prospect',
                'has_traded' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            app(SupplierService::class)->findOrCreate('https://steamcommunity.com/id/prospect');

            expect((bool) DB::table('suppliers')->where('url', 'https://steamcommunity.com/id/prospect')->value('has_traded'))->toBeFalse();
        });
    });

    describe('upsert()', function () {

        it('creates a new supplier with has_traded = false', function () {
            app(SupplierService::class)->upsert([
                'steam_id' => '76561198000000001',
                'url' => 'https://steamcommunity.com/id/prospect',
            ]);

            expect((bool) DB::table('suppliers')->where('steam_id', '76561198000000001')->value('has_traded'))->toBeFalse();
        });

        it('does not change has_traded when upserting an existing supplier', function () {
            DB::table('suppliers')->insert([
                'steam_id' => '76561198000000001',
                'url' => 'https://steamcommunity.com/id/prospect',
                'has_traded' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            app(SupplierService::class)->upsert([
                'steam_id' => '76561198000000001',
                'url' => 'https://steamcommunity.com/id/prospect',
            ]);

            expect((bool) DB::table('suppliers')->where('steam_id', '76561198000000001')->value('has_traded'))->toBeTrue();
        });
    });
});
