<?php

/*
|--------------------------------------------------------------------------
| ImportHeaderValidator — unit tests
|--------------------------------------------------------------------------
|
| PHP puro — sem DB, sem bootstrap do framework.
|
*/

use App\Domain\Import\ImportHeaderValidator;

describe('ImportHeaderValidator', function () {

    describe('validate()', function () {

        it('returns no errors when all headers match', function () {
            $actual = ImportHeaderValidator::EXPECTED_COLUMNS; // cópia exata

            expect(ImportHeaderValidator::validate($actual))->toBeEmpty();
        });

        it('returns an error for each mismatched column', function () {
            $actual = ImportHeaderValidator::EXPECTED_COLUMNS;
            $actual['A'] = 'Data Errada';
            $actual['C'] = 'URL perfil Errada';

            $errors = ImportHeaderValidator::validate($actual);

            expect($errors)->toHaveCount(2);
        });

        it('includes the column letter, expected name and actual value in the error message', function () {
            $actual = ImportHeaderValidator::EXPECTED_COLUMNS;
            $actual['J'] = 'Titulo';

            $errors = ImportHeaderValidator::validate($actual);

            expect($errors[0])->toContain('J')
                ->and($errors[0])->toContain('Nome do Jogo')
                ->and($errors[0])->toContain('Titulo');
        });

        it('returns an error when a column is missing from the actual headers', function () {
            $actual = ImportHeaderValidator::EXPECTED_COLUMNS;
            unset($actual['I']); // remove coluna da Chave

            $errors = ImportHeaderValidator::validate($actual);

            expect($errors)->toHaveCount(1)
                ->and($errors[0])->toContain('I');
        });

        it('returns errors for all 10 columns when actual headers are empty', function () {
            expect(ImportHeaderValidator::validate([]))->toHaveCount(10);
        });
    });
});
