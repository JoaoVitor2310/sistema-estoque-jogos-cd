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

    it('counts the window from the last time the giveaway was seen, to the end of the last day', function () {
        $period = giveawayPeriodLasting(7);

        expect(GiveawayPolicy::flaggedUntil($period)->toDateTimeString())
            ->toBe($period->lastSeenAt->copy()->addDays(GiveawayPolicy::SHORT_WINDOW_DAYS)->endOfDay()->toDateTimeString());
    });
});

describe('GiveawayPolicy::isFlagged()', function () {

    it('flags an active giveaway however old it is', function () {
        $period = giveawayPeriodLasting(400, GiveawayStatus::Active);

        expect(GiveawayPolicy::isFlagged($period, $period->lastSeenAt->copy()->addYears(2)))->toBeTrue();
    });

    it('flags an ended giveaway up to the last instant of its window', function (int $duration, int $window) {
        $period = giveawayPeriodLasting($duration);
        $until = $period->lastSeenAt->copy()->addDays($window)->endOfDay();

        expect(GiveawayPolicy::isFlagged($period, $until))->toBeTrue()
            ->and(GiveawayPolicy::isFlagged($period, $until->copy()->addSecond()))->toBeFalse();
    })->with([
        'short' => [7, GiveawayPolicy::SHORT_WINDOW_DAYS],
        'medium' => [20, GiveawayPolicy::MEDIUM_WINDOW_DAYS],
        'long' => [45, GiveawayPolicy::LONG_WINDOW_DAYS],
    ]);
});

describe('GiveawayPolicy — the last day of the window', function () {

    it('still flags the game late on the last day, past the hour it was last seen', function () {
        // Visto pela última vez às 12h: às 23h do último dia o prazo ainda diz
        // "até hoje", e o jogo tem de continuar contando.
        $period = giveawayPeriodLasting(7);
        $lateOnLastDay = $period->lastSeenAt->copy()->addDays(GiveawayPolicy::SHORT_WINDOW_DAYS)->setTime(23, 0);

        expect(GiveawayPolicy::isFlagged($period, $lateOnLastDay))->toBeTrue()
            ->and(GiveawayPolicy::isFlagged($period, $lateOnLastDay->copy()->addDay()->startOfDay()))->toBeFalse();
    });
});

describe('GiveawayPolicy::isGameFlagged()', function () {

    it('flags the game when any of its giveaways is in the window', function () {
        $now = Carbon::parse('2026-01-01 12:00:00');
        $old = new GiveawayPeriod(GiveawayStatus::Ended, $now->copy()->subDays(400), $now->copy()->subDays(393));
        $recent = new GiveawayPeriod(GiveawayStatus::Ended, $now->copy()->subDays(17), $now->copy()->subDays(10));

        expect(GiveawayPolicy::isGameFlagged([$old, $recent], $now))->toBeTrue()
            ->and(GiveawayPolicy::isGameFlagged([$old], $now))->toBeFalse();
    });

    it('does not flag a game without giveaways', function () {
        expect(GiveawayPolicy::isGameFlagged([], Carbon::parse('2026-01-01')))->toBeFalse();
    });
});

describe('GiveawayPolicy::windowRule()', function () {

    it('exposes the window bands from the constants', function () {
        expect(GiveawayPolicy::windowRule())->toBe([
            'short_max_days' => GiveawayPolicy::SHORT_MAX_DAYS,
            'short_window_days' => GiveawayPolicy::SHORT_WINDOW_DAYS,
            'medium_max_days' => GiveawayPolicy::MEDIUM_MAX_DAYS,
            'medium_window_days' => GiveawayPolicy::MEDIUM_WINDOW_DAYS,
            'long_window_days' => GiveawayPolicy::LONG_WINDOW_DAYS,
        ]);
    });
});

describe('GiveawayPolicy::oldestFlaggableLastSeen()', function () {

    it('reaches back as far as the longest window', function () {
        $now = Carbon::parse('2026-07-01 00:00:00');

        expect(GiveawayPolicy::oldestFlaggableLastSeen($now)->toDateTimeString())
            ->toBe($now->copy()->subDays(GiveawayPolicy::LONG_WINDOW_DAYS)->toDateTimeString());
    });
});
