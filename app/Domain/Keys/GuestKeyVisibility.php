<?php

namespace App\Domain\Keys;

/**
 * O que o sistema divulga publicamente sobre uma key.
 *
 * A página de keys é aberta a visitantes, então esta lista é a fronteira entre
 * o que é público e o que é segredo comercial — código da chave, fornecedor,
 * margem de venda.
 *
 * É fonte única de propósito: a lista governa ao mesmo tempo **quais campos
 * saem na resposta** e **por quais campos se pode filtrar**. Governar só a
 * resposta deixava o total de resultados respondendo perguntas sobre os campos
 * escondidos, que é o vazamento que esta classe existe para fechar — manter as
 * duas listas separadas era garantir que uma hora elas divergissem.
 */
class GuestKeyVisibility
{
    /** @var string[] */
    public const FIELDS = [
        'identified_platform',
        'game_name',
        'region',
        'market_price',
        'individual_cost',
        'min_api',
        'max_api',
        'purchase_profit',
        'purchase_profit_percent',
        'acquired_at',
        'sold_at',
        'expires_at',
    ];
}
