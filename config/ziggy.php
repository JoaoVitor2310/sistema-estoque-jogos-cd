<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Grupos de rotas expostos ao navegador
    |--------------------------------------------------------------------------
    |
    | Por padrão o `@routes` publica o mapa **inteiro** de rotas na página. Isso
    | é inofensivo enquanto todo visitante é da equipe, e deixou de ser quando a
    | entrega de trade (docs/adr/0008) passou a renderizar o mesmo blade para o
    | supplier: nomes e URIs de `/financial-months`, `/keys/search` e companhia
    | não são segredo, mas descrevem a superfície do sistema para quem não tem
    | nada que ver com ela.
    |
    | O grupo abaixo é o que a página da entrega recebe. As rotas dela não são
    | usadas por nome no Vue (a página monta os caminhos a partir da URL), mas o
    | grupo precisa existir: sem `@routes` nenhum, o helper global `route` some
    | e o mixin registrado em `app.js` quebra na criação do app.
    |
    */

    'groups' => [
        'delivery' => ['deliveries.*'],
    ],

];
