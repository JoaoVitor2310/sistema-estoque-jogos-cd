<?php

namespace App\Domain\Bundles;

/**
 * Corpo da pesquisa de preço dos jogos de um bundle no price_researcher.
 *
 * Existe como Domain porque os dois critérios são regra de negócio, não
 * detalhe de transporte: quem decide o que vale a pena olhar num bundle é a
 * operação, e o critério do bundle é mais frouxo que o das listas de
 * fornecedor — na compra de bundle o que interessa é ver o pacote inteiro.
 *
 * Transformação pura: quem chama já resolveu nome do bundle, jogos e segredo.
 */
final class BundleResearchRequest
{
    /**
     * Pico mínimo de jogadores em 24h para o jogo entrar no resultado.
     *
     * `1` e não `0` porque zero desliga o filtro do lado de lá: o piso existe
     * só para cortar o que o SteamCharts não conhece ou nunca teve um jogador.
     * Bem abaixo do piso das listas de fornecedor, de propósito — a decisão
     * aqui é sobre o bundle inteiro, e jogo impopular ainda soma no pacote.
     */
    public const MIN_POPULARITY = 1;

    /**
     * Não descarta jogo sem oferta ativa na Gamivo: no bundle queremos o
     * retrato do pacote todo, inclusive do que ainda não é vendável lá.
     *
     * Consequência a jusante: jogo sem oferta volta com `gamivo_id` nulo, e a
     * linha da trade nasce sem ele — o id é resolvido depois, na importação
     * das keys.
     */
    public const CHECK_GAMIVO_OFFER = false;

    /**
     * Piso de preço, em euros: jogo com preço **menor ou igual** a ele é
     * descartado. Zero porque o bundle é precificado completo — o jogo barato
     * também soma no pacote.
     *
     * Precisa ir explícito: omitido, o price_researcher aplica o default dele
     * (€0,50) e cortaria justamente os jogos mais baratos do bundle. Como o
     * corte inclui o próprio piso, jogo a exatamente €0,00 ainda sai.
     */
    public const MIN_PRICE = 0;

    /**
     * O schema do price_researcher é estrito: campo desconhecido no corpo faz a
     * request falhar com 400. Não acrescente chaves aqui sem combinar antes.
     *
     * @param  array<int, string>  $gameNames
     * @return array<string, mixed>
     */
    public static function payload(string $bundleName, array $gameNames, string $internalSecret): array
    {
        return [
            'minPopularity' => self::MIN_POPULARITY,
            'gameNames' => array_values($gameNames),
            'checkGamivoOffer' => self::CHECK_GAMIVO_OFFER,
            'minPrice' => self::MIN_PRICE,
            'internal_secret' => $internalSecret,
            // Volta idêntico no callback: é por ele que a trade nasce com o
            // nome do bundle e as linhas reencontram o bundle de origem.
            'title' => $bundleName,
        ];
    }
}
