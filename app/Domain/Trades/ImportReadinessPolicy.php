<?php

namespace App\Domain\Trades;

use App\Domain\Enums\TradeImportBlocker;

/**
 * Quando uma trade está pronta para virar keys.
 *
 * É a autoridade única sobre essa decisão. Antes o critério não existia deste
 * lado da fronteira e qualquer lote chegava ao import; agora a recusa é do
 * domínio, e quem chama apenas reporta o motivo.
 *
 * A verificação é sobre a trade inteira porque o import é atômico — uma linha
 * incompleta derruba o lote, não a si mesma.
 */
final class ImportReadinessPolicy
{
    /**
     * Uma linha entra no lote quando tem nome **ou** preço de mercado.
     *
     * O critério é frouxo de propósito: linha em branco é rascunho legítimo de
     * uma trade em negociação e simplesmente fica fora do lote, enquanto linha
     * meio preenchida é engano do operador e precisa ser apontada. Preço zerado
     * conta como preenchido justamente para virar
     * [[TradeImportBlocker::MissingMarketPrice]] em vez de sumir do lote em
     * silêncio.
     */
    public static function isFilled(?string $gameName, ?string $marketPrice): bool
    {
        return trim((string) $gameName) !== '' || trim((string) $marketPrice) !== '';
    }

    /**
     * Tudo que impede o import, de uma vez — o operador corrige numa passada só.
     *
     * @param  list<array{game_name: ?string, market_price: ?string, key_code: ?string}>  $lines  na ordem de exibição
     * @return list<TradeImportBlocker>
     */
    public static function blockers(array $lines, ?string $tf2Quantity, ?string $supplierUrl): array
    {
        $filled = array_values(array_filter(
            $lines,
            fn (array $line) => self::isFilled($line['game_name'], $line['market_price']),
        ));

        if ($filled === []) {
            return [TradeImportBlocker::NoFilledLine];
        }

        $blockers = [];

        if (self::anyLine($filled, fn (array $line) => trim((string) $line['game_name']) === '')) {
            $blockers[] = TradeImportBlocker::MissingGameName;
        }

        if (self::anyLine($filled, fn (array $line) => ! self::isPositive($line['market_price']))) {
            $blockers[] = TradeImportBlocker::MissingMarketPrice;
        }

        if (self::anyLine($filled, fn (array $line) => trim((string) $line['key_code']) === '')) {
            $blockers[] = TradeImportBlocker::MissingKeyCode;
        }

        if (! self::hasTf2Quantity($tf2Quantity)) {
            $blockers[] = TradeImportBlocker::MissingTf2Quantity;
        }

        if (trim((string) $supplierUrl) === '') {
            $blockers[] = TradeImportBlocker::MissingSupplierUrl;
        }

        return $blockers;
    }

    /**
     * Se o total de TF2 acertado está declarado.
     *
     * Público porque a entrega do supplier exige o mesmo campo antes de aceitar
     * o envio, e as duas pontas precisam da **mesma** régua: medir lá por outro
     * critério deixaria passar o `0`, que é justamente o que faria o rateio de
     * `individual_cost` rodar sem custo.
     */
    public static function hasTf2Quantity(?string $tf2Quantity): bool
    {
        return self::isPositive($tf2Quantity);
    }

    /**
     * @param  list<array{game_name: ?string, market_price: ?string, key_code: ?string}>  $lines
     * @param  callable(array{game_name: ?string, market_price: ?string, key_code: ?string}): bool  $predicate
     */
    private static function anyLine(array $lines, callable $predicate): bool
    {
        foreach ($lines as $line) {
            if ($predicate($line)) {
                return true;
            }
        }

        return false;
    }

    /** Preço e quantidade só valem se somarem alguma coisa — zero é ausência. */
    private static function isPositive(?string $value): bool
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' && is_numeric($trimmed) && (float) $trimmed > 0;
    }
}
