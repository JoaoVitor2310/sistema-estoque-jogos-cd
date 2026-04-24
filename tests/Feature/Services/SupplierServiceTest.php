<?php

/*
|--------------------------------------------------------------------------
| SupplierService — characterization tests
|--------------------------------------------------------------------------
|
| SupplierService é infraestrutura pura: find-or-create de fornecedores
| pela supplier_url (URL do perfil Steam).
|
| A idempotência é crítica porque RegisterKeyUseCase chama findOrCreate
| para cada key do lote — múltiplas keys do mesmo fornecedor não podem
| criar registros duplicados.
|
*/

use App\Services\Suppliers\SupplierService;
use Illuminate\Support\Facades\DB;

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('SupplierService', function () {

    describe('findOrCreate()', function () {

        it('creates a new supplier when the profile does not exist', function () {
            $profile = 'https://steamcommunity.com/id/brandnew';

            expect(DB::table('fornecedor')->where('supplier_url', $profile)->exists())->toBeFalse();

            app(SupplierService::class)->findOrCreate($profile);

            expect(DB::table('fornecedor')->where('supplier_url', $profile)->exists())->toBeTrue();
        });

        it('returns the ID of the newly created supplier', function () {
            $id = app(SupplierService::class)->findOrCreate('https://steamcommunity.com/id/newseller');

            expect($id)->toBeInt()->toBeGreaterThan(0);
        });

        it('returns the existing supplier ID when the profile already exists', function () {
            // Pré-cria o fornecedor
            $existingId = DB::table('fornecedor')->insertGetId([
                'supplier_url' => 'https://steamcommunity.com/id/existing',
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

            $count = DB::table('fornecedor')->where('supplier_url', $profile)->count();

            expect($count)->toBe(1);
        });

        it('treats different profiles as different suppliers', function () {
            $id1 = app(SupplierService::class)->findOrCreate('https://steamcommunity.com/id/sellerA');
            $id2 = app(SupplierService::class)->findOrCreate('https://steamcommunity.com/id/sellerB');

            expect($id1)->not->toBe($id2);
        });
    });
});
