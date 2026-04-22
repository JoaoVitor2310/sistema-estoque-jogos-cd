<?php

/*
|--------------------------------------------------------------------------
| AdminGate — characterization tests (6.1)
|--------------------------------------------------------------------------
|
| Verifica que o Gate 'is-admin' lê o e-mail do admin a partir de
| config('app.admin_email') — nunca de um valor hardcoded.
|
*/

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;

describe('Gate is-admin', function () {

    it('grants admin access when the user email matches ADMIN_EMAIL', function () {
        Config::set('app.admin_email', 'admin@example.com');

        $user = User::factory()->make(['email' => 'admin@example.com']);

        expect(Gate::forUser($user)->allows('is-admin'))->toBeTrue();
    });

    it('denies access when the user email does not match ADMIN_EMAIL', function () {
        Config::set('app.admin_email', 'admin@example.com');

        $user = User::factory()->make(['email' => 'other@example.com']);

        expect(Gate::forUser($user)->allows('is-admin'))->toBeFalse();
    });

    it('denies access when ADMIN_EMAIL is empty', function () {
        Config::set('app.admin_email', '');

        $user = User::factory()->make(['email' => 'any@example.com']);

        expect(Gate::forUser($user)->allows('is-admin'))->toBeFalse();
    });

    it('denies access when ADMIN_EMAIL is null', function () {
        Config::set('app.admin_email', null);

        $user = User::factory()->make(['email' => 'any@example.com']);

        expect(Gate::forUser($user)->allows('is-admin'))->toBeFalse();
    });

    it('is case-sensitive — uppercase and lowercase emails are treated as different', function () {
        Config::set('app.admin_email', 'Admin@Example.com');

        $user = User::factory()->make(['email' => 'admin@example.com']);

        expect(Gate::forUser($user)->allows('is-admin'))->toBeFalse();
    });
});
