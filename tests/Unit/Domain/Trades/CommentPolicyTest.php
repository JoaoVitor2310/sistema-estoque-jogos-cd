<?php

use App\Domain\Trades\CommentPolicy;
use Carbon\Carbon;

$profitable = [['name' => 'Half-Life', 'price_euro' => 4.50, 'tf2_price' => 0.45]];

describe('CommentPolicy::shouldComment', function () use ($profitable) {

    it('returns false when profitable is empty', function () {
        expect(CommentPolicy::shouldComment([], false, null))->toBeFalse();
        expect(CommentPolicy::shouldComment([], true, null))->toBeFalse();
        expect(CommentPolicy::shouldComment([], false, Carbon::now()->subWeeks(3)))->toBeFalse();
    });

    it('returns true when never commented on this topic (last_commented_at is null)', function () use ($profitable) {
        expect(CommentPolicy::shouldComment($profitable, false, null))->toBeTrue();
    });

    it('returns true when games changed since last comment', function () use ($profitable) {
        $recent = Carbon::now()->subDays(1);

        expect(CommentPolicy::shouldComment($profitable, true, $recent))->toBeTrue();
    });

    it('returns true when interval has passed (exactly 14 days)', function () use ($profitable) {
        $now = Carbon::now();
        $lastCommentedAt = $now->copy()->subDays(CommentPolicy::INTERVAL_DAYS);

        expect(CommentPolicy::shouldComment($profitable, false, $lastCommentedAt, $now))->toBeTrue();
    });

    it('returns true when interval has passed (more than 14 days)', function () use ($profitable) {
        $now = Carbon::now();
        $lastCommentedAt = $now->copy()->subDays(20);

        expect(CommentPolicy::shouldComment($profitable, false, $lastCommentedAt, $now))->toBeTrue();
    });

    it('returns false when within interval and games have not changed', function () use ($profitable) {
        $now = Carbon::now();
        $lastCommentedAt = $now->copy()->subDays(CommentPolicy::INTERVAL_DAYS - 1);

        expect(CommentPolicy::shouldComment($profitable, false, $lastCommentedAt, $now))->toBeFalse();
    });

    it('returns true when within interval but games changed', function () use ($profitable) {
        $now = Carbon::now();
        $lastCommentedAt = $now->copy()->subDays(1);

        expect(CommentPolicy::shouldComment($profitable, true, $lastCommentedAt, $now))->toBeTrue();
    });
});
