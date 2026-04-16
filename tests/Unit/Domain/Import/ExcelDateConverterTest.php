<?php

/*
|--------------------------------------------------------------------------
| ExcelDateConverter — unit tests
|--------------------------------------------------------------------------
|
| PHP puro — sem DB, sem bootstrap do framework.
| Serial dates calculados com a fórmula: (serial - 25569) × 86400 = Unix timestamp.
|
*/

use App\Domain\Import\ExcelDateConverter;

describe('ExcelDateConverter', function () {

    describe('convert()', function () {

        // ── Valores ausentes ──────────────────────────────────────────────

        it('returns null for null input', function () {
            expect(ExcelDateConverter::convert(null))->toBeNull();
        });

        it('returns null for empty string', function () {
            expect(ExcelDateConverter::convert(''))->toBeNull();
        });

        // ── Serial numérico ───────────────────────────────────────────────

        it('converts a known Excel serial date to Y-m-d', function () {
            // Serial 45955 = 25/10/2025
            // (45955 - 25569) × 86400 = Unix timestamp de 2025-10-25
            expect(ExcelDateConverter::convert(45955))->toBe('2025-10-25');
        });

        it('converts serial 44927 to 2023-01-01', function () {
            expect(ExcelDateConverter::convert(44927))->toBe('2023-01-01');
        });

        it('converts float serial (Excel stores dates as floats)', function () {
            // Mesmo serial, passado como float
            expect(ExcelDateConverter::convert(45955.0))->toBe('2025-10-25');
        });

        it('returns null for serial zero or negative (invalid)', function () {
            expect(ExcelDateConverter::convert(0))->toBeNull();
            expect(ExcelDateConverter::convert(-1))->toBeNull();
        });

        // ── String d/m/Y ──────────────────────────────────────────────────

        it('converts a string in Brazilian date format (dd-mm-yyyy with slashes)', function () {
            expect(ExcelDateConverter::convert('25/10/2025'))->toBe('2025-10-25');
        });

        // ── String Y-m-d ──────────────────────────────────────────────────

        it('converts a string already in ISO format (yyyy-mm-dd)', function () {
            expect(ExcelDateConverter::convert('2025-10-25'))->toBe('2025-10-25');
        });

        // ── String d-m-Y ──────────────────────────────────────────────────

        it('converts a string in European date format (dd-mm-yyyy with dashes)', function () {
            expect(ExcelDateConverter::convert('25-10-2025'))->toBe('2025-10-25');
        });

        // ── String inválida ───────────────────────────────────────────────

        it('returns null for an unrecognized string format', function () {
            // Antes retornava today() como fallback — agora null (o Service decide)
            expect(ExcelDateConverter::convert('not-a-date'))->toBeNull();
        });

        it('returns null for a random string', function () {
            expect(ExcelDateConverter::convert('outubro de 2025'))->toBeNull();
        });
    });
});
