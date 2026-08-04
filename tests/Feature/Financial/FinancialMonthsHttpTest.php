<?php

use App\Domain\Enums\AccountType;
use App\Domain\Enums\FinancialMonthStatus;
use App\Models\AuthorizedUsers;
use App\Models\FinancialMonth;
use App\Models\User;

function actingAsAuthorizedFinancialUser(): User
{
    $user = User::factory()->create();
    AuthorizedUsers::create(['name' => $user->name, 'email' => $user->email, 'status' => true]);

    return $user;
}

/** @return array<string, mixed> */
function bootstrapPayload(array $overrides = []): array
{
    return array_merge([
        'year' => 2026,
        'month' => 7,
        'opening_balances' => ['principal' => 3000.00],
    ], $overrides);
}

describe('FinancialMonth HTTP contracts', function () {

    beforeEach(fn () => $this->actingAs(actingAsAuthorizedFinancialUser()));

    it('bootstraps the first draft', function () {
        $this->postJson('/financial-months', bootstrapPayload())
            ->assertStatus(201);

        $month = FinancialMonth::first();
        expect($month->status)->toBe(FinancialMonthStatus::Draft)
            ->and($month->month)->toBe(7)
            ->and($month->movements()->where('account_type', AccountType::Principal)->exists())->toBeTrue();
    });

    it('rejects an out-of-range month (422)', function () {
        $this->postJson('/financial-months', bootstrapPayload(['month' => 13]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['month']);
    });

    describe('with an open draft', function () {

        beforeEach(fn () => $this->postJson('/financial-months', bootstrapPayload())->assertStatus(201));

        it('records an income into the chosen account', function () {
            $this->postJson('/financial-months/movements', [
                'category' => 'income',
                'account' => 'principal',
                'amount' => 2000.00,
                'description' => 'Saque da Gamivo',
                'income_category' => 'gamivo_payout',
            ])->assertStatus(201);

            expect(FinancialMonth::first()->movements()->where('category', 'income')->exists())->toBeTrue();
        });

        it('rejects an income without an account (422)', function () {
            $this->postJson('/financial-months/movements', [
                'category' => 'income', 'amount' => 100, 'income_category' => 'gamivo_payout',
            ])->assertStatus(422)->assertJsonValidationErrors(['account']);
        });

        it('rejects an income without an amount (422)', function () {
            $this->postJson('/financial-months/movements', [
                'category' => 'income', 'account' => 'principal', 'income_category' => 'gamivo_payout',
            ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
        });

        it('rejects an income without an income category (422)', function () {
            $this->postJson('/financial-months/movements', [
                'category' => 'income', 'account' => 'principal', 'amount' => 100,
            ])->assertStatus(422)->assertJsonValidationErrors(['income_category']);
        });

        it('rejects an expense without an expense category (422)', function () {
            $this->postJson('/financial-months/movements', [
                'category' => 'expense', 'account' => 'principal', 'amount' => 100,
            ])->assertStatus(422)->assertJsonValidationErrors(['expense_category']);
        });

        it('rejects a category that needs its own endpoint (422)', function () {
            foreach (['transfer', 'tf2_allocation', 'partner_distribution', 'opening'] as $category) {
                $this->postJson('/financial-months/movements', [
                    'category' => $category,
                    'account' => 'principal',
                    'amount' => 100.00,
                ])->assertStatus(422)->assertJsonValidationErrors(['category']);
            }
        });

        it('rejects debiting a reserve fund without a justification (422)', function () {
            // O erro tem que cair no campo `description`, não virar mensagem genérica.
            $this->postJson('/financial-months/movements', [
                'category' => 'expense',
                'account' => 'emergency',
                'amount' => 100.00,
                'expense_category' => 'other',
            ])->assertStatus(422)->assertJsonValidationErrors(['description']);
        });

        it('allows debiting a reserve fund when justified', function () {
            $this->postJson('/financial-months/movements', [
                'category' => 'expense',
                'account' => 'emergency',
                'amount' => 100.00,
                'description' => 'Emergência médica',
                'expense_category' => 'other',
            ])->assertStatus(201);
        });

        it('does not demand a justification to credit a reserve fund', function () {
            $this->postJson('/financial-months/movements', [
                'category' => 'income',
                'account' => 'emergency',
                'amount' => 100.00,
                'income_category' => 'external_investment',
            ])->assertStatus(201);
        });

        it('records a tf2 purchase against the budget', function () {
            $this->postJson('/financial-months/movements', [
                'category' => 'tf2_purchase',
                'quantity' => 100,
                'unit_price' => 10.00,
            ])->assertStatus(201);

            $movement = FinancialMonth::first()->movements()->where('category', 'tf2_purchase')->first();
            expect($movement->account_type)->toBe(AccountType::Tf2)
                ->and((float) $movement->amount)->toBe(1000.00);
        });

        describe('transfers', function () {

            it('records both legs of a transfer', function () {
                $this->postJson('/financial-months/transfers', [
                    'source' => 'principal',
                    'destination' => 'reinvestment',
                    'amount' => 600.00,
                ])->assertStatus(201);

                $legs = FinancialMonth::first()->movements()->where('category', 'transfer')->get();

                expect($legs)->toHaveCount(2)
                    ->and($legs->pluck('group_id')->unique())->toHaveCount(1);
            });

            it('transfers a fraction of the source balance', function () {
                $this->postJson('/financial-months/transfers', [
                    'source' => 'principal',
                    'destination' => 'reinvestment',
                    'fraction' => 0.20,
                ])->assertStatus(201);

                $debit = FinancialMonth::first()->movements()
                    ->where('category', 'transfer')
                    ->where('account_type', 'principal')
                    ->first();

                // 20% dos 3.000 de abertura.
                expect((float) $debit->amount)->toBe(600.00);
            });

            it('rejects a transfer into the same account (422)', function () {
                $this->postJson('/financial-months/transfers', [
                    'source' => 'principal',
                    'destination' => 'principal',
                    'amount' => 100.00,
                ])->assertStatus(422)->assertJsonValidationErrors(['destination']);
            });

            it('rejects a transfer with neither amount nor fraction (422)', function () {
                $this->postJson('/financial-months/transfers', [
                    'source' => 'principal',
                    'destination' => 'reinvestment',
                ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
            });

            it('rejects a transfer carrying both amount and fraction (422)', function () {
                $this->postJson('/financial-months/transfers', [
                    'source' => 'principal',
                    'destination' => 'reinvestment',
                    'amount' => 100.00,
                    'fraction' => 0.20,
                ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
            });

            it('rejects draining a reserve fund without a justification (422)', function () {
                $this->postJson('/financial-months/transfers', [
                    'source' => 'emergency',
                    'destination' => 'principal',
                    'amount' => 100.00,
                ])->assertStatus(422)->assertJsonValidationErrors(['description']);
            });
        });

        describe('tf2 allocations', function () {

            it('allocates the monthly budget', function () {
                $this->postJson('/financial-months/tf2-allocations', [
                    'quantity' => 100,
                    'unit_price' => 10.00,
                ])->assertStatus(201);

                $credit = FinancialMonth::first()->movements()
                    ->where('category', 'tf2_allocation')
                    ->where('account_type', 'tf2')
                    ->first();

                expect((float) $credit->amount)->toBe(1000.00)
                    ->and((float) $credit->quantity)->toBe(100.0);
            });

            it('rejects an allocation without quantity or price (422)', function () {
                $this->postJson('/financial-months/tf2-allocations', [])
                    ->assertStatus(422)
                    ->assertJsonValidationErrors(['quantity', 'unit_price']);
            });
        });

        describe('partner distributions', function () {

            it('records one debit per partner', function () {
                $this->postJson('/financial-months/partner-distributions', [
                    'source' => 'principal',
                    'amount' => 800.00,
                    'partner_one_share' => 0.50,
                ])->assertStatus(201);

                $legs = FinancialMonth::first()->movements()->where('category', 'partner_distribution')->get();

                expect($legs)->toHaveCount(2)
                    ->and($legs->pluck('partner_slot')->sort()->values()->all())->toBe([1, 2]);
            });

            it('rejects a distribution without the partner share (422)', function () {
                $this->postJson('/financial-months/partner-distributions', [
                    'source' => 'principal',
                    'amount' => 800.00,
                ])->assertStatus(422)->assertJsonValidationErrors(['partner_one_share']);
            });

            it('rejects a share above 1 (422)', function () {
                $this->postJson('/financial-months/partner-distributions', [
                    'source' => 'principal',
                    'amount' => 800.00,
                    'partner_one_share' => 1.5,
                ])->assertStatus(422)->assertJsonValidationErrors(['partner_one_share']);
            });
        });

        describe('deleting an entry', function () {

            it('removes both legs of a transfer from either side', function () {
                $this->postJson('/financial-months/transfers', [
                    'source' => 'principal',
                    'destination' => 'reinvestment',
                    'amount' => 600.00,
                ])->assertStatus(201);

                $credit = FinancialMonth::first()->movements()
                    ->where('category', 'transfer')
                    ->where('account_type', 'reinvestment')
                    ->first();

                $this->deleteJson("/financial-months/movements/{$credit->id}")
                    ->assertStatus(200)
                    ->assertJsonPath('data.deleted', 2);

                expect(FinancialMonth::first()->movements()->where('category', 'transfer')->count())->toBe(0);
            });

            it('refuses to delete an opening balance (422)', function () {
                $opening = FinancialMonth::first()->movements()->where('category', 'opening')->first();

                $this->deleteJson("/financial-months/movements/{$opening->id}")->assertStatus(422);

                expect(FinancialMonth::first()->movements()->where('category', 'opening')->exists())->toBeTrue();
            });

            it('404s on an unknown movement', function () {
                $this->deleteJson('/financial-months/movements/99999')->assertStatus(404);
            });
        });

        it('closes the month', function () {
            $this->postJson('/financial-months/close')->assertStatus(201);

            expect(FinancialMonth::where('month', 7)->first()->status)->toBe(FinancialMonthStatus::Closed);
        });
    });

    it('refuses to delete a movement of a closed month (422)', function () {
        $this->postJson('/financial-months', bootstrapPayload())->assertStatus(201);
        $july = FinancialMonth::where('month', 7)->first();
        $opening = $july->movements()->first();

        $this->postJson('/financial-months/close')->assertStatus(201);

        $this->deleteJson("/financial-months/movements/{$opening->id}")->assertStatus(422);

        expect($july->movements()->count())->toBeGreaterThan(0);
    });

    it('reopens the most recent closed month', function () {
        $this->postJson('/financial-months', bootstrapPayload())->assertStatus(201);
        $this->postJson('/financial-months/close')->assertStatus(201);

        $july = FinancialMonth::where('month', 7)->first();

        $this->postJson("/financial-months/{$july->id}/reopen")->assertStatus(200);

        expect($july->fresh()->status)->toBe(FinancialMonthStatus::Draft);
    });
});
