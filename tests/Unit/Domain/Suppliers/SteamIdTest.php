<?php

use App\Domain\Suppliers\SteamId;

describe('SteamId::isValid()', function () {

    it('accepts the SteamID64 of an individual account', function (string $steamId) {
        expect(SteamId::isValid($steamId))->toBeTrue();
    })->with(['76561197960265728', '76561198012345678', '76561199160265728']);

    it('refuses anything else', function (string $steamId) {
        expect(SteamId::isValid($steamId))->toBeFalse();
    })->with([
        'too short' => ['7656119801234567'],
        'too long' => ['765611980123456789'],
        'wrong prefix' => ['12345678901234567'],
        'profile url' => ['https://steamcommunity.com/profiles/76561198012345678'],
        'custom url name' => ['carcadeals'],
        'trailing space' => ['76561198012345678 '],
        'empty' => [''],
    ]);
});
