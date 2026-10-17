<?php

namespace App\Services\External;

/**
 * Corpo das pesquisas de preço dos jogos de um bundle no price_researcher.
 *
 * Os critérios (popularidade mínima, piso de preço, exigência de oferta na
 * Gamivo) moram nos **presets** do price_researcher, não aqui: mandamos só o
 * nome do preset, e mudar a regra de bundle vira mudança de lá, sem deploy
 * deste lado. Valores vigentes em `GET /api/games/presets`.
 *
 * Por isso mora em infraestrutura, não no Domain: é só a forma do contrato
 * HTTP. Transformação pura: quem chama já resolveu nome do bundle, jogos e
 * segredo.
 */
final class BundleResearchRequest
{
    /** "Pesquisar Preços" de um bundle: vira trade pelo callback. */
    public const PRESET_RESEARCH = 'bundle-research';

    /** Preço de lançamento dos jogos, na sync de bundles. */
    public const PRESET_SYNC = 'bundle-sync';

    /** Entrega síncrona: os preços voltam na própria resposta, sem criar trade. */
    public const DELIVERY_RESPOND = 'respond';

    /**
     * Pesquisa assíncrona: o resultado volta pelo callback e vira uma trade.
     *
     * O schema do price_researcher é estrito: campo desconhecido no corpo faz a
     * request falhar com 400. Não acrescente chaves aqui sem combinar antes —
     * e não mande critério avulso (`minPopularity` & cia.): ele sobrescreve o
     * preset e esconderia qualquer mudança futura dele.
     *
     * @param  array<int, string>  $gameNames
     * @return array<string, mixed>
     */
    public static function payload(string $bundleName, array $gameNames, string $internalSecret): array
    {
        return [
            'preset' => self::PRESET_RESEARCH,
            'gameNames' => array_values($gameNames),
            'internal_secret' => $internalSecret,
            // Volta idêntico no callback: é por ele que a trade nasce com o
            // nome do bundle e as linhas reencontram o bundle de origem.
            'title' => $bundleName,
        ];
    }

    /**
     * Pesquisa síncrona do preço de lançamento: os jogos voltam na resposta.
     *
     * @param  array<int, string>  $gameNames
     * @return array<string, mixed>
     */
    public static function launchPricesPayload(array $gameNames, string $internalSecret): array
    {
        return [
            'preset' => self::PRESET_SYNC,
            'delivery' => self::DELIVERY_RESPOND,
            'gameNames' => array_values($gameNames),
            'internal_secret' => $internalSecret,
        ];
    }
}
