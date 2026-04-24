<?php

/*
|--------------------------------------------------------------------------
| ImportRowValidator — unit tests
|--------------------------------------------------------------------------
|
| Testa apenas as REGRAS e MENSAGENS definidas no Domain.
| A execução via Validator::make() é responsabilidade do FileService.
|
*/

use App\Domain\Import\ImportRowValidator;

describe('ImportRowValidator', function () {

    describe('RULES constant', function () {

        it('declares game_name as required', function () {
            expect(ImportRowValidator::RULES['game_name'])->toContain('required');
        });

        it('declares key_code as required', function () {
            expect(ImportRowValidator::RULES['key_code'])->toContain('required');
        });

        it('declares supplier_url as required', function () {
            expect(ImportRowValidator::RULES['supplier_url'])->toContain('required');
        });

        it('declares tf2_quantity as required, numeric, and with a minimum of 0.01', function () {
            // Célula vazia do Excel vira 0.0 via floatval — min:0.01 rejeita isso
            expect(ImportRowValidator::RULES['tf2_quantity'])
                ->toContain('required')
                ->and(ImportRowValidator::RULES['tf2_quantity'])->toContain('numeric')
                ->and(ImportRowValidator::RULES['tf2_quantity'])->toContain('min:0.01');
        });

        it('declares region as nullable', function () {
            expect(ImportRowValidator::RULES['region'])->toContain('nullable');
        });

        it('declares acquired_at as nullable', function () {
            expect(ImportRowValidator::RULES['acquired_at'])->toContain('nullable');
        });
    });

    describe('messages()', function () {

        it('includes the row number in every message', function () {
            $messages = ImportRowValidator::messages(42);

            foreach ($messages as $message) {
                expect($message)->toContain('42');
            }
        });

        it('returns a message for key_code', function () {
            expect(ImportRowValidator::messages(1))->toHaveKey('key_code.required');
        });

        it('returns a message for tf2_quantity numeric', function () {
            expect(ImportRowValidator::messages(1))->toHaveKey('tf2_quantity.numeric');
        });

        it('returns a message for tf2_quantity min explaining the division-by-zero risk', function () {
            $messages = ImportRowValidator::messages(5);

            expect($messages)->toHaveKey('tf2_quantity.min')
                ->and($messages['tf2_quantity.min'])->toContain('5')
                ->and($messages['tf2_quantity.min'])->toContain('0.01')
                ->and($messages['tf2_quantity.min'])->toContain('divisão por zero');
        });
    });
});
