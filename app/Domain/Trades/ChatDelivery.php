<?php

namespace App\Domain\Trades;

/**
 * A entrega das keys pelo chat: o supplier cola uma key por jogo da oferta
 * vigente, e o chatbot repassa pares (linha da trade, key). Ver docs/adr/0017.
 *
 * O que a página da entrega deixa em aberto, aqui é fixo: só as linhas da
 * oferta recebem key, a entrega é só completa, e o total acertado é o da
 * oferta — o supplier não digita valor nenhum.
 */
final class ChatDelivery
{
    /**
     * Se uma linha da oferta vigente é entregue pelo chat: só enquanto ainda tem
     * linha na trade. Linha que a equipe apagou depois da oferta
     * (`trade_line_id` nulo) não tem onde receber key, e o chatbot a deixa fora
     * do modelo.
     *
     * @param  int|null  $tradeLineId  o `trade_line_id` da linha da oferta
     */
    public static function isDeliverable(?int $tradeLineId): bool
    {
        return $tradeLineId !== null;
    }

    /**
     * As linhas pedidas que não estão entre as entregáveis. Pedido com uma delas
     * é recusado inteiro: é erro de quem montou o pedido, e gravar o resto
     * esconderia o erro.
     *
     * @param  list<int>  $requested
     * @param  list<int>  $deliverable
     * @return list<int>
     */
    public static function outsideOffer(array $requested, array $deliverable): array
    {
        return array_values(array_diff($requested, $deliverable));
    }

    /**
     * As linhas entregáveis ainda sem key, na ordem da oferta.
     *
     * @param  list<int>  $deliverable
     * @param  array<int, string|null>  $keyCodes  `key_code` gravado, por id da linha
     * @return list<int>
     */
    public static function missingKeys(array $deliverable, array $keyCodes): array
    {
        return array_values(array_filter(
            $deliverable,
            fn (int $lineId) => ! self::hasKey($keyCodes[$lineId] ?? null),
        ));
    }

    /** Key em branco não é key: o mesmo critério da contagem no aviso à equipe. */
    public static function hasKey(?string $keyCode): bool
    {
        return trim((string) $keyCode) !== '';
    }

    /**
     * O total acertado numa entrega pelo chat, em centavos da moeda da oferta:
     * a soma do que o supplier leu pelos jogos que entregou.
     *
     * Soma por centavo, e não em ponto flutuante, para bater com a soma das
     * linhas que ele leu. Jogo que a equipe tirou da trade depois da oferta não
     * é entregue, e por isso não entra.
     *
     * @param  list<float>  $prices  o valor de cada linha entregável da oferta
     */
    public static function agreedTotalCents(array $prices): int
    {
        return (int) array_sum(array_map(fn (float $price) => (int) round($price * 100), $prices));
    }
}
