<?php

/*
|--------------------------------------------------------------------------
| GamivoApiService — tests
|--------------------------------------------------------------------------
|
| Covers the Gamivo API wrapper. All requests are intercepted via
| Http::fake() — no real calls are made to the production API.
|
| Test base URL: http://fake-gamivo (defined in phpunit.xml)
|
*/

use App\Mail\GamivoTokenExpiredMail;
use App\Services\External\GamivoApiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

describe('GamivoApiService', function () {

    // ── getActiveOffers ───────────────────────────────────────────────────────

    describe('getActiveOffers()', function () {

        it('returns all offers by iterating multiple pages automatically', function () {
            // Page 1 with 100 items (signals more may exist), page 2 with 1 item (end)
            $page1 = array_fill(0, 100, ['id' => 1, 'product_id' => 10, 'status' => 1, 'seller_price' => 3.00]);
            $page2 = [['id' => 2, 'product_id' => 20, 'status' => 1, 'seller_price' => 5.00]];

            Http::fake([
                '*/api/public/v1/offers*' => Http::sequence()
                    ->push($page1, 200)
                    ->push($page2, 200),
            ]);

            expect((new GamivoApiService)->getActiveOffers())->toHaveCount(101);
        });

        it('stops paginating when the first page returns fewer than 100 items', function () {
            Http::fake([
                '*/api/public/v1/offers*' => Http::response(
                    [['id' => 1, 'product_id' => 10, 'status' => 1, 'seller_price' => 3.00]],
                    200
                ),
            ]);

            expect((new GamivoApiService)->getActiveOffers())->toHaveCount(1);
        });

        it('returns an empty array when there are no offers', function () {
            Http::fake(['*/api/public/v1/offers*' => Http::response([], 200)]);

            expect((new GamivoApiService)->getActiveOffers())->toBe([]);
        });
    });

    // ── getOffersForProduct ───────────────────────────────────────────────────

    describe('getOffersForProduct()', function () {

        it('returns offers sorted by retail_price ASC regardless of API order', function () {
            Http::fake([
                '*/api/public/v1/products/123/offers' => Http::response([
                    // Non-integer values so json_encode → json_decode preserves float type
                    ['id' => 1, 'seller_name' => 'B', 'retail_price' => 5.10, 'completed_orders' => 100, 'wholesale_mode' => 0],
                    ['id' => 2, 'seller_name' => 'A', 'retail_price' => 2.55, 'completed_orders' => 500, 'wholesale_mode' => 0],
                    ['id' => 3, 'seller_name' => 'C', 'retail_price' => 3.85, 'completed_orders' => 200, 'wholesale_mode' => 0],
                ], 200),
            ]);

            $offers = (new GamivoApiService)->getOffersForProduct(123);

            expect($offers)->toHaveCount(3)
                ->and($offers[0]['retail_price'])->toBe(2.55)
                ->and($offers[1]['retail_price'])->toBe(3.85)
                ->and($offers[2]['retail_price'])->toBe(5.10);
        });

        it('returns an empty array when the product has no offers', function () {
            Http::fake(['*/api/public/v1/products/999/offers' => Http::response([], 200)]);

            expect((new GamivoApiService)->getOffersForProduct(999))->toBe([]);
        });
    });

    // ── updateOffer ───────────────────────────────────────────────────────────

    describe('updateOffer()', function () {

        it('returns the offerId when the update succeeds', function () {
            Http::fake(['*/api/public/v1/offers/99*' => Http::response(99, 200)]);

            expect((new GamivoApiService)->updateOffer(99, ['wholesale_mode' => 0, 'seller_price' => 2.99]))->toBe(99);
        });

        it('silences the "Wait for current action" error and returns the offerId without throwing', function () {
            Http::fake([
                '*/api/public/v1/offers/77*' => Http::response(
                    ['reason' => 'Wait for the current action to end. Progress: 50/100'],
                    400
                ),
            ]);

            expect((new GamivoApiService)->updateOffer(77, ['wholesale_mode' => 0, 'seller_price' => 2.99]))->toBe(77);
        });
    });

    // ── createOffer ───────────────────────────────────────────────────────────

    describe('createOffer()', function () {

        it('returns the offerId when the offer is created successfully', function () {
            Http::fake(['*/api/public/v1/offers' => Http::response(12345, 201)]);

            $offerId = (new GamivoApiService)->createOffer([
                'product' => 999,
                'wholesale_mode' => 0,
                'seller_price' => 3.50,
                'status' => 1,
                'keys' => 1,
                'is_preorder' => false,
            ]);

            expect($offerId)->toBe(12345);
        });

        it('reactivates an existing offer when the API returns the offerId in the error message', function () {
            Http::fake([
                '*/api/public/v1/offers' => Http::response(
                    ['reason' => 'Offer already exists [88888]'],
                    400
                ),
                '*/api/public/v1/offers/88888/change-status' => Http::response(88888, 200),
            ]);

            expect((new GamivoApiService)->createOffer([
                'product' => 999,
                'wholesale_mode' => 0,
                'seller_price' => 3.00,
                'status' => 1,
                'keys' => 1,
                'is_preorder' => false,
            ]))->toBe(88888);
        });
    });

    // ── uploadKeys + waitForUpload ────────────────────────────────────────────

    describe('uploadKeys() and waitForUpload()', function () {

        it('uploadKeys returns the jobId and waitForUpload completes when the job responds Done', function () {
            Http::fake([
                '*/api/public/v1/offers/55/keys/upload' => Http::response(9001, 202),
                // The API returns the JSON string "Done" (with quotes) — json_decode returns the string 'Done'
                '*/api/public/v1/offers/55/jobs/9001/result' => Http::response('"Done"', 200),
            ]);

            $service = new GamivoApiService;
            $jobId = $service->uploadKeys(55, ['CODE-1234-56789']);

            expect($jobId)->toBe(9001);

            // Should not throw
            $service->waitForUpload(55, $jobId, maxAttempts: 3);
        });

        it('waitForUpload throws RuntimeException when the job fails', function () {
            Http::fake([
                '*/api/public/v1/offers/55/keys/upload' => Http::response(9002, 202),
                '*/api/public/v1/offers/55/jobs/9002/result' => Http::response(
                    ['status' => 'failed', 'progress' => null],
                    200
                ),
            ]);

            $service = new GamivoApiService;
            $jobId = $service->uploadKeys(55, ['CODE-FAIL']);

            expect(fn () => $service->waitForUpload(55, $jobId, maxAttempts: 3))
                ->toThrow(\RuntimeException::class);
        });

        it('waitForUpload throws RuntimeException when the job exceeds the attempt limit', function () {
            Http::fake([
                '*/api/public/v1/offers/55/keys/upload' => Http::response(9003, 202),
                // Always returns "running" — never completes
                '*/api/public/v1/offers/55/jobs/9003/result' => Http::response(
                    ['status' => 'running', 'progress' => '0/100'],
                    200
                ),
            ]);

            $service = new GamivoApiService;
            $jobId = $service->uploadKeys(55, ['CODE-TIMEOUT']);

            expect(fn () => $service->waitForUpload(55, $jobId, maxAttempts: 1))
                ->toThrow(\RuntimeException::class, 'timed out');
        });
    });

    // ── getSalesHistory ───────────────────────────────────────────────────────

    describe('getSalesHistory()', function () {

        it('returns sales for the requested page', function () {
            $sales = [
                ['product_id' => 123, 'order_id' => 'uuid-1', 'profit' => 2.99, 'seller_tax' => 0.0, 'quantity' => 1, 'created_at' => '2025-04-13UTC17:44:480'],
            ];

            // A API retorna { count: N, data: [...] } — o serviço extrai apenas o array de vendas
            Http::fake(['*/api/public/v1/accounts/sales/history/0/25*' => Http::response(['count' => 1, 'data' => $sales], 200)]);

            expect((new GamivoApiService)->getSalesHistory(['statuses' => ['COMPLETED']]))->toEqual($sales);
        });

        it('returns an empty array when there are no sales', function () {
            Http::fake(['*/api/public/v1/accounts/sales/history/0/25*' => Http::response([], 200)]);

            expect((new GamivoApiService)->getSalesHistory(['statuses' => ['COMPLETED']]))->toBe([]);
        });
    });

    // ── Expired token ─────────────────────────────────────────────────────────

    describe('expired token', function () {

        it('sends an alert email and throws RuntimeException on UNAUTHORIZED_EXPIRED_TOKEN', function () {
            Mail::fake();

            Http::fake([
                '*/api/public/v1/offers*' => Http::response(
                    ['codeMessage' => 'UNAUTHORIZED_EXPIRED_TOKEN', 'message' => 'Token has expired'],
                    401
                ),
            ]);

            expect(fn () => (new GamivoApiService)->getActiveOffers())
                ->toThrow(\RuntimeException::class, 'UNAUTHORIZED_EXPIRED_TOKEN');

            Mail::assertSent(GamivoTokenExpiredMail::class);
        });

        it('throws RuntimeException without sending an email for other auth errors', function () {
            Mail::fake();

            Http::fake([
                '*/api/public/v1/offers*' => Http::response(
                    ['codeMessage' => 'UNAUTHORIZED_INVALID_TOKEN', 'message' => 'Invalid token'],
                    401
                ),
            ]);

            expect(fn () => (new GamivoApiService)->getActiveOffers())
                ->toThrow(\RuntimeException::class, 'UNAUTHORIZED_INVALID_TOKEN');

            Mail::assertNothingSent();
        });
    });
});
