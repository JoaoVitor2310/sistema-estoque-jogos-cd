<?php

use App\Domain\Games\GiveawayPeriod;
use Carbon\Carbon;

describe('GiveawayPeriod::startDate()', function () {

    it('uses the date published by the API', function () {
        $start = GiveawayPeriod::startDate(
            Carbon::parse('2026-10-01 10:00:00'),
            Carbon::parse('2026-09-01 10:00:00'),
            Carbon::parse('2026-10-18 12:00:00'),
        );

        expect($start->toDateTimeString())->toBe('2026-10-01 10:00:00');
    });

    it('keeps the recorded start when the API stops sending the date', function () {
        $start = GiveawayPeriod::startDate(null, Carbon::parse('2026-10-01 10:00:00'), Carbon::parse('2026-10-18 12:00:00'));

        expect($start->toDateTimeString())->toBe('2026-10-01 10:00:00');
    });

    it('falls back to the first time the giveaway was seen', function () {
        $start = GiveawayPeriod::startDate(null, null, Carbon::parse('2026-10-18 12:00:00'));

        expect($start->toDateTimeString())->toBe('2026-10-18 12:00:00');
    });
});
