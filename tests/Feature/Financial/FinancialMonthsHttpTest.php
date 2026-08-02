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
            ])->assertStatus(201);

            expect(FinancialMonth::first()->movements()->where('category', 'income')->exists())->toBeTrue();
        });

        it('rejects an income without an account (422)', function () {
            $this->postJson('/financial-months/movements', ['category' => 'income', 'amount' => 100])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['account']);
        });

        it('rejects an income without an amount (422)', function () {
            $this->postJson('/financial-months/movements', ['category' => 'income', 'account' => 'principal'])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['amount']);
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
            ])->assertStatus(422)->assertJsonValidationErrors(['description']);
        });

        it('allows debiting a reserve fund when justified', function () {
            $this->postJson('/financial-months/movements', [
                'category' => 'expense',
                'account' => 'emergency',
                'amount' => 100.00,
                'description' => 'Emergência médica',
            ])->assertStatus(201);
        });

        it('does not demand a justification to credit a reserve fund', function () {
            $this->postJson('/financial-months/movements', [
                'category' => 'income',
                'account' => 'emergency',
                'amount' => 100.00,
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

        it('closes the month', function () {
            $this->postJson('/financial-months/close')->assertStatus(201);

            expect(FinancialMonth::where('month', 7)->first()->status)->toBe(FinancialMonthStatus::Closed);
        });
    });

    it('reopens the most recent closed month', function () {
        $this->postJson('/financial-months', bootstrapPayload())->assertStatus(201);
        $this->postJson('/financial-months/close')->assertStatus(201);

        $july = FinancialMonth::where('month', 7)->first();

        $this->postJson("/financial-months/{$july->id}/reopen")->assertStatus(200);

        expect($july->fresh()->status)->toBe(FinancialMonthStatus::Draft);
    });
});
