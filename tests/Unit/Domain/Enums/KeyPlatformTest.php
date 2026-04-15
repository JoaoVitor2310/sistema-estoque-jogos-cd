<?php

use App\Domain\Enums\KeyPlatform;

describe('KeyPlatform enum', function () {

    describe('fromKeyFormat()', function () {
        it('detects Steam from the XXXXX-XXXXX-XXXXX format', function () {
            expect(KeyPlatform::fromKeyFormat('ABCDE-12345-FGHIJ'))->toBe(KeyPlatform::Steam)
                ->and(KeyPlatform::fromKeyFormat('A1B2C-D3E4F-G5H6I'))->toBe(KeyPlatform::Steam);
        });

        it('detects Xbox from a 5-group format', function () {
            expect(KeyPlatform::fromKeyFormat('ABCDE-12345-FGHIJ-KLMNO-PQRST'))->toBe(KeyPlatform::Xbox);
        });

        it('detects GOG from a 4-group format', function () {
            expect(KeyPlatform::fromKeyFormat('ABCDE-12345-FGHIJ-KLMNO'))->toBe(KeyPlatform::GOG);
        });

        it('detects Epic Games Store from the EGS_ prefix', function () {
            expect(KeyPlatform::fromKeyFormat('EGS_ABCDEFGH12345'))->toBe(KeyPlatform::Epic);
        });

        it('detects Epic Games Store from the EPICGAMES_ prefix', function () {
            expect(KeyPlatform::fromKeyFormat('EPICGAMES_SOMEKEY123'))->toBe(KeyPlatform::Epic);
        });

        it('detects EA from the XXXX-XXXX-XXXX-XXXX-XXXX format', function () {
            expect(KeyPlatform::fromKeyFormat('ABCD-1234-EFGH-5678-IJKL'))->toBe(KeyPlatform::EA);
        });

        it('returns Unknown for unrecognised formats', function () {
            expect(KeyPlatform::fromKeyFormat('invalid-format'))->toBe(KeyPlatform::Unknown)
                ->and(KeyPlatform::fromKeyFormat(''))->toBe(KeyPlatform::Unknown)
                ->and(KeyPlatform::fromKeyFormat('12345'))->toBe(KeyPlatform::Unknown);
        });
    });

    describe('isSteam()', function () {
        it('returns true only for the Steam case', function () {
            expect(KeyPlatform::Steam->isSteam())->toBeTrue();
        });

        it('returns false for every other platform', function () {
            expect(KeyPlatform::GOG->isSteam())->toBeFalse()
                ->and(KeyPlatform::Xbox->isSteam())->toBeFalse()
                ->and(KeyPlatform::EA->isSteam())->toBeFalse()
                ->and(KeyPlatform::Unknown->isSteam())->toBeFalse();
        });
    });

    describe('string values', function () {
        it('are the platform display names', function () {
            expect(KeyPlatform::Steam->value)->toBe('Steam')
                ->and(KeyPlatform::EA->value)->toBe('EA')
                ->and(KeyPlatform::Epic->value)->toBe('Epic Games Store')
                ->and(KeyPlatform::GOG->value)->toBe('GOG')
                ->and(KeyPlatform::Xbox->value)->toBe('Xbox')
                ->and(KeyPlatform::PSN->value)->toBe('PlayStation Network')
                ->and(KeyPlatform::Unknown->value)->toBe('Desconhecido');
        });
    });
});
