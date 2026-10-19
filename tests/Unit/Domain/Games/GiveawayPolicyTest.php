<?php

use App\Domain\Enums\GiveawayStatus;
use App\Domain\Games\GiveawayPeriod;
use App\Domain\Games\GiveawayPolicy;
use Carbon\Carbon;

/** Giveaway que durou `$days` dias, visto pela última vez em 01/01/2026 às 12h. */
function giveawayPeriodLasting(int $days, GiveawayStatus $status = GiveawayStatus::Ended): GiveawayPeriod
{
    $lastSeen = Carbon::parse('2026-01-01 12:00:00');

    return new GiveawayPeriod($status, $lastSeen->copy()->subDays($days), $lastSeen);
}

describe('GiveawayPolicy::windowDays()', function () {

    it('grows the window with the duration of the giveaway', function (int $duration, int $window) {
        expect(GiveawayPolicy::windowDays($duration))->toBe($window);
    })->with([
        'one day' => [1, GiveawayPolicy::SHORT_WINDOW_DAYS],
        'last day of the short tier' => [GiveawayPolicy::SHORT_MAX_DAYS, GiveawayPolicy::SHORT_WINDOW_DAYS],
        'first day of the medium tier' => [GiveawayPolicy::SHORT_MAX_DAYS + 1, GiveawayPolicy::MEDIUM_WINDOW_DAYS],
        'last day of the medium tier' => [GiveawayPolicy::MEDIUM_MAX_DAYS, GiveawayPolicy::MEDIUM_WINDOW_DAYS],
        'first day of the long tier' => [GiveawayPolicy::MEDIUM_MAX_DAYS + 1, GiveawayPolicy::LONG_WINDOW_DAYS],
        'months long' => [115, GiveawayPolicy::LONG_WINDOW_DAYS],
    ]);
});

describe('GiveawayPolicy::durationDays()', function () {

    it('counts calendar days, not hours', function () {
        // Publicado às 23h e visto às 9h, 15 dias de calendário depois: ~14,4 dias em horas.
        $period = new GiveawayPeriod(
            GiveawayStatus::Ended,
            Carbon::parse('2026-01-01 23:00:00'),
            Carbon::parse('2026-01-16 09:00:00'),
        );

        expect(GiveawayPolicy::durationDays($period))->toBe(15);
    });
});

describe('GiveawayPolicy::flaggedUntil()', function () {

    it('has no deadline while the giveaway is active', function () {
        expect(GiveawayPolicy::flaggedUntil(giveawayPeriodLasting(200, GiveawayStatus::Active)))->toBeNull();
    });

    it('counts the window from the last time the giveaway was seen', function () {
        $period = giveawayPeriodLasting(7);

        expect(GiveawayPolicy::flaggedUntil($period)->toDateTimeString())
            ->toBe($period->lastSeenAt->copy()->addDays(GiveawayPolicy::SHORT_WINDOW_DAYS)->toDateTimeString());
    });
});

describe('GiveawayPolicy::isFlagged()', function () {

    it('flags an active giveaway however old it is', function () {
        $period = giveawayPeriodLasting(400, GiveawayStatus::Active);

        expect(GiveawayPolicy::isFlagged($period, $period->lastSeenAt->copy()->addYears(2)))->toBeTrue();
    });

    it('flags an ended giveaway up to the last instant of its window', function (int $duration, int $window) {
        $period = giveawayPeriodLasting($duration);
        $until = $period->lastSeenAt->copy()->addDays($window);

        expect(GiveawayPolicy::isFlagged($period, $until))->toBeTrue()
            ->and(GiveawayPolicy::isFlagged($period, $until->copy()->addSecond()))->toBeFalse();
    })->with([
        'short' => [7, GiveawayPolicy::SHORT_WINDOW_DAYS],
        'medium' => [20, GiveawayPolicy::MEDIUM_WINDOW_DAYS],
        'long' => [45, GiveawayPolicy::LONG_WINDOW_DAYS],
    ]);
});

describe('GiveawayPolicy::oldestFlaggableLastSeen()', function () {

    it('reaches back as far as the longest window', function () {
        $now = Carbon::parse('2026-07-01 00:00:00');

        expect(GiveawayPolicy::oldestFlaggableLastSeen($now)->toDateTimeString())
            ->toBe($now->copy()->subDays(GiveawayPolicy::LONG_WINDOW_DAYS)->toDateTimeString());
    });
});
