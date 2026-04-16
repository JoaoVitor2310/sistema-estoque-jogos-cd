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

        it('declares nomeJogo as required', function () {
            expect(ImportRowValidator::RULES['nomeJogo'])->toContain('required');
        });

        it('declares chaveRecebida as required', function () {
            expect(ImportRowValidator::RULES['chaveRecebida'])->toContain('required');
        });

        it('declares perfilOrigem as required', function () {
            expect(ImportRowValidator::RULES['perfilOrigem'])->toContain('required');
        });

        it('declares qtdTF2 as required, numeric, and with a minimum of 0.01', function () {
            // Célula vazia do Excel vira 0.0 via floatval — min:0.01 rejeita isso
            expect(ImportRowValidator::RULES['qtdTF2'])
                ->toContain('required')
                ->and(ImportRowValidator::RULES['qtdTF2'])->toContain('numeric')
                ->and(ImportRowValidator::RULES['qtdTF2'])->toContain('min:0.01');
        });

        it('declares region as nullable', function () {
            expect(ImportRowValidator::RULES['region'])->toContain('nullable');
        });

        it('declares dataAdquirida as nullable', function () {
            expect(ImportRowValidator::RULES['dataAdquirida'])->toContain('nullable');
        });
    });

    describe('messages()', function () {

        it('includes the row number in every message', function () {
            $messages = ImportRowValidator::messages(42);

            foreach ($messages as $message) {
                expect($message)->toContain('42');
            }
        });

        it('returns a message for chaveRecebida', function () {
            expect(ImportRowValidator::messages(1))->toHaveKey('chaveRecebida.required');
        });

        it('returns a message for qtdTF2 numeric', function () {
            expect(ImportRowValidator::messages(1))->toHaveKey('qtdTF2.numeric');
        });

        it('returns a message for qtdTF2 min explaining the division-by-zero risk', function () {
            $messages = ImportRowValidator::messages(5);

            expect($messages)->toHaveKey('qtdTF2.min')
                ->and($messages['qtdTF2.min'])->toContain('5')
                ->and($messages['qtdTF2.min'])->toContain('0.01')
                ->and($messages['qtdTF2.min'])->toContain('divisão por zero');
        });
    });
});
