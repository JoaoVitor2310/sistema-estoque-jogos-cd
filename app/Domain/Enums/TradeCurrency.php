<?php

namespace App\Domain\Enums;

/**
 * A moeda em que o lote de uma trade é pago ao fornecedor.
 *
 * É fato do lote inteiro, como o canal de compra: a quantidade acertada
 * (`trades.amount`) está nesta moeda — TF2 Keys, euros ou dólares. Quem escolhe
 * a moeda de uma lista na prospecção é o price_researcher, lendo o que o
 * fornecedor aceita; a equipe pode corrigi-la na aba. O valor `tf2` é o caso
 * comum e o default. Fora da trade, o sistema todo segue em TF2 e euro: o import
 * converte o valor acertado para TF2 antes do rateio.
 */
enum TradeCurrency: string
{
    /** Pagamento em TF2 Keys — o caso comum. */
    case Tf2 = 'tf2';

    /** Pagamento em euros (PayPal conta como euro). */
    case Eur = 'eur';

    /** Pagamento em dólares. */
    case Usd = 'usd';

    /** Preço de 1 TF2 key medido em TF2: a própria unidade, por definição. */
    public const TF2_UNIT_PRICE = 1.0;

    /** Se o pagamento é em dinheiro, e não em TF2 Keys. */
    public function isCash(): bool
    {
        return $this !== self::Tf2;
    }

    /**
     * Se há preço da TF2 nesta moeda para converter a oferta. Em TF2 sempre; em
     * dinheiro, só com preço positivo — zero é "ainda não cadastrado".
     */
    public function hasTf2Price(float $tf2PriceInCurrency): bool
    {
        return ! $this->isCash() || $tf2PriceInCurrency > 0;
    }
}
