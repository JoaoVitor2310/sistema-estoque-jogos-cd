# Chatbot — referência de integração

O chatbot (`chatbot-steam`) conversa pela Steam com os suppliers. Ele **não guarda cópia** de trade nem de key: lê do Sistema Estoque a oferta, repassa as contrapropostas e as keys que o supplier cola no chat, e o Estoque nunca o chama. Quem avisa o chatbot do fim de uma precificação é o price-researcher, por webhook, depois de criar a trade em `POST /api/trades` (contrato em [`PRICE_RESEARCHER.md`](PRICE_RESEARCHER.md#trade-com-oferta--post-apitrades)).

As regras da oferta estão em [`PRODUCT.md`](PRODUCT.md#oferta-ao-supplier); as decisões de desenho, nos ADRs [0015](adr/0015-chatbot-reads-the-delivery-credential-through-the-api.md), [0016](adr/0016-supplier-offers-recorded-as-immutable-records.md) e [0017](adr/0017-supplier-delivers-keys-through-the-chatbot.md); o que falta, em [`IMPROVEMENTS.md`](IMPROVEMENTS.md).

## Fluxos

Do mais comum para o mais raro:

| Caso | Estoque | price-cd → chatbot (webhook) | Chatbot → supplier |
|---|---|---|---|
| 1. Há jogo a ofertar | Cria a trade com a **primeira oferta** (lucro da categoria do supplier) e responde `201` com o `uuid` | Trade criada, com o `uuid` | Lê `GET /api/trades/{uuid}` e mostra a oferta e o modelo para colar as keys; marca `message-sent` |
| 2. Nenhum jogo interessa (nada lucrativo, tudo encalhado ou em giveaway, ou supplier bloqueado) | **Não cria trade** e responde `200 { "created": false }` | `no_trade` | "Unfortunately none of these games fit…" |
| 3. Supplier pede mais | Decide e grava a próxima rodada (no máximo 3 ofertas) | — (o chatbot chama `POST /api/trades/{uuid}/offers`) | Mostra a oferta nova, ou avisa que a vigente já é a final |
| 4. Supplier cola as keys | Grava cada key na linha da trade (`PATCH .../keys`) e diz quais faltam | — | Mostra o que entendeu e pede confirmação |
| 5. Supplier confirma | Fecha a entrega com o **total da oferta vigente** como valor acertado (`POST .../deliver`) e avisa a equipe por e-mail | — | Agradece; a trade sai da negociação |
| 6. Supplier prefere a página (a equipe manda o link) | A oferta não muda: as keys e o valor acertado entram pela página da entrega | — | — |

## Casos de conversa

| Situação | Como tratar |
|---|---|
| O supplier quer vender **só parte** da lista | É com a equipe (`0 - Talk to Carca Deals`): a entrega pelo chat é só completa, e `POST .../deliver` recusa com `missing_keys`. A equipe pode mandar a página da entrega, onde linha em branco é resposta legítima ([ADR 0008](adr/0008-supplier-fills-trade-through-tokenised-link.md)) |
| O supplier pede **em outra moeda** | O pedido vai sempre na moeda da trade; converter é decidir valor, e quem decide é o Estoque. Moeda diferente é outra trade |
| O supplier aceita uma **oferta antiga** | Vale sempre a vigente: o chatbot só mostra a vigente, e a entrega pelo chat grava o total dela como valor acertado |
| O supplier pede mais por **um jogo só** | A contraproposta recebe um **total**, distribuído entre os jogos na proporção da oferta vigente — não um valor por jogo ([`PRODUCT.md`](PRODUCT.md#oferta-ao-supplier)) |

## Autenticação e rede

| Item | Como é |
|---|---|
| Token | `Authorization: Bearer <CHATBOT_API_SECRET>` — o segredo **do chatbot** (`VerifySecret::chatbot()`), diferente do `EXTERNAL_SECRET` do price-researcher, que não abre estas rotas; sem ele ou errado, `401` |
| Endereço | `http://web-server-cd:8080`, pela rede `sistema-estoque-cd` — a porta interna do nginx, não publicada no host. Pelo domínio público, toda rota `/api` responde `404` |
| Rotas | `routes/api.php`, prefixo `/api`, sem sessão nem cookie |
| `delivery_url` | Sempre no domínio público (`APP_URL`), mesmo lido pela porta interna: é o link que vai para o supplier |
| Caminho desconhecido em `/api` | `404 { "message": "Not found." }`, nunca redirect |
| Ids | A trade é sempre o `uuid`; o supplier, o SteamID64. Uuid ou SteamID64 malformado é `404` no roteador |
| Datas | ISO 8601 com o fuso da aplicação (`APP_TIMEZONE`), em toda a API — ex.: `2026-10-29T22:38:22-03:00` |

## `GET /api/trades/{uuid}` — a trade com a oferta vigente

```json
{
  "uuid": "1fd5f585-5e5b-4f2e-9283-579aa744d82a",
  "number": 2,
  "created_at": "2026-10-29T22:38:22-03:00",
  "currency": "eur",
  "state": "negotiating",
  "offer": {
    "round": 1,
    "is_final": false,
    "created_at": "2026-10-29T22:38:22-03:00",
    "total_price": 4.16,
    "lines": [
      { "line_id": 811, "game_name": "Black Book", "region": "", "price": 0.96 },
      { "line_id": 812, "game_name": "Portal 2", "region": "EU", "price": 3.20 }
    ]
  },
  "delivery_url": "https://…/deliveries/1fd5f585-5e5b-4f2e-9283-579aa744d82a",
  "delivery_code": "ABCD-EFGH-JKMN-PQRS"
}
```

| Campo | Observação |
|---|---|
| `number` | O **número da trade** no supplier: a primeira trade dele é 1, a segunda 2. É o que o supplier digita para escolher a trade (ver `GET /api/suppliers/{steamId}/trades/{number}`). Nunca muda e nunca é reaproveitado, nem quando a trade é importada ou apagada — lacunas são normais. `null` só em trade sem supplier |
| `currency` | Moeda da trade (e da oferta): `tf2`, `eur` ou `usd` |
| `state` | `negotiating`, `awaiting_review` (entregue, esperando a equipe) ou `imported` |
| `offer` | A oferta vigente — a de rodada mais alta. `null` quando a trade não tem oferta (criada por outro caminho) |
| `offer.lines[]` | Só os jogos ofertados: `line_id`, `game_name`, `region` (`""` = key global) e `price` na moeda da trade. Jogo encalhado ou em giveaway não aparece |
| `offer.lines[].line_id` | A linha da trade que recebe a key daquele jogo na [entrega pelo chat](#entrega-pelo-chat). `null` quando a equipe apagou a linha depois da oferta: o jogo não é mais entregue nem pago, e fica fora do modelo |
| `offer.total_price` | Soma dos `price`, já arredondados |
| `offer.is_final` | Última rodada (a 3ª): não há contraproposta depois dela |
| `delivery_url`, `delivery_code` | O acesso à página da entrega. O chatbot não os manda mais ao supplier (a entrega é pelo chat); a equipe os manda a quem pedir. `null` depois do import, quando a credencial deixa de valer ([ADR 0015](adr/0015-chatbot-reads-the-delivery-credential-through-the-api.md)) |

**Nunca sai:** preço de mercado, popularidade, Gamivo ID, lucro, categoria do supplier, oferta máxima, jogo deixado fora da oferta, `key_code`, ofertas anteriores. Trade inexistente ou apagada responde `404`.

## `GET /api/suppliers/{steamId}/trades` — trades abertas de um supplier

```json
{
  "trades": [
    { "uuid": "…", "number": 2, "created_at": "…", "currency": "tf2", "games_count": 2, "total_price": 5.8 }
  ]
}
```

- Só trades **em negociação**: entregue ou importada fica de fora.
- Da mais recente para a mais antiga.
- `games_count` e `total_price` são da oferta vigente (`0` e `null` sem oferta).
- **Sem acesso à entrega:** para o link e o código, ler a trade pelo `uuid`.
- Supplier sem trades abertas, ou desconhecido: `200` com a lista vazia.

## `GET /api/suppliers/{steamId}/trades/{number}` — a trade que o supplier escolheu

A trade daquele supplier com aquele número, **no mesmo formato de `GET /api/trades/{uuid}`**. É o que o chatbot chama quando o supplier responde com o número de uma trade da lista: o par (supplier, número) é estável, então o chatbot não precisa guardar qual número correspondia a qual uuid em cada tela.

- **Qualquer estado**, importada inclusive — ao contrário da lista. O `state` diz o que responder ("essa trade já foi entregue") em vez de "número inválido".
- `404` quando o supplier não tem trade com esse número, quando ela foi apagada, ou quando o número é de outro supplier.
- Número que não é inteiro positivo (`0`, `-1`, texto) é `404` no roteador.

## `POST /api/trades/{uuid}/offers` — contraproposta

O supplier pediu mais: o chatbot repassa o **total** pedido, na moeda da trade, e o Estoque decide a próxima rodada. Regras em [`PRODUCT.md`](PRODUCT.md#oferta-ao-supplier).

```json
{ "requested_total": 6.00 }
```

| Resposta | Quando |
|---|---|
| `201` com a trade, no formato de `GET /api/trades/{uuid}` | Nova rodada gravada. `offer.round` diz qual; `offer.is_final` é `true` na 3ª |
| `409 { "reason": "offer_is_final" }` | A vigente já é a final (rodada 3) |
| `409 { "reason": "nothing_to_concede" }` | Nenhum jogo pode subir: a vigente já é o melhor que o Estoque oferta |
| `409 { "reason": "trade_not_negotiating" }` | A trade já foi entregue ou importada |
| `409 { "reason": "no_offer" }` | A trade não tem oferta (nasceu por outro caminho) |
| `422 { "reason": "not_an_increase" }` | O pedido não passa da oferta vigente — aceitar é mandar as keys ([entrega pelo chat](#entrega-pelo-chat)) |
| `422` com `errors` | `requested_total` ausente, zero, negativo ou com mais de 2 casas |
| `404` / `503` | Trade inexistente / sem cotação da TF2 para recalcular. Nada é gravado |

O chatbot nunca calcula: mostra ao supplier a oferta que voltou. A nova rodada pode ser **menor que o pedido** (quando passa do que o Estoque cede nesta rodada) ou **exatamente o pedido**.

## Entrega pelo chat

O supplier cola uma key depois de cada jogo do modelo e confirma; o chatbot manda ao Estoque **pares (linha, key)**, nunca nomes. Duas etapas, como na página: preencher e entregar. Decisão em [ADR 0017](adr/0017-supplier-delivers-keys-through-the-chatbot.md); regras em [`PRODUCT.md`](PRODUCT.md#entrega-pelo-chat).

### `PATCH /api/suppliers/{steamId}/trades/{number}/keys` — preencher

```json
{ "keys": [ { "line_id": 811, "key_code": "AAAAA-BBBBB-CCCCC" } ] }
```

Grava a key de cada linha informada. Colar de novo substitui a key das linhas que vierem; as outras ficam como estão. Só `key_code`: região, bundle e validade ficam com a equipe.

| Resposta | Quando |
|---|---|
| `200 { "missing_line_ids": [812] }` | Gravado. A lista diz as linhas da oferta ainda sem key; **vazia** quer dizer que já dá para pedir a confirmação. A key nunca volta |
| `422 { "reason": "line_not_in_offer", "line_ids": [...] }` | Alguma linha não está na oferta vigente (ou é de outra trade). Nada é gravado |
| `422` com `errors` | `keys` ausente ou vazio, ou com mais de 75 pares (um bloco do modelo; oferta maior chega em vários pedidos), `line_id` repetido ou não inteiro, `key_code` em branco, campo além de `line_id` e `key_code` |
| `409 { "reason": "trade_not_negotiating" }` | A trade já foi entregue ou importada |
| `409 { "reason": "no_offer" }` | A trade não tem oferta com jogo a entregar |
| `404` | O supplier não tem trade com esse número |

### `POST /api/suppliers/{steamId}/trades/{number}/deliver` — entregar

Sem corpo. Fecha a entrega como o botão da página: grava `delivered_at`, manda o e-mail à equipe e a trade sai da negociação. O valor acertado (`amount`) é o **total da oferta vigente**, gravado pelo Estoque — o chatbot não informa valor.

| Resposta | Quando |
|---|---|
| `200` com a trade, no formato de `GET /api/trades/{uuid}` | Entregue: `state` é `awaiting_review` |
| `409 { "reason": "missing_keys", "line_ids": [...] }` | Falta key em alguma linha da oferta: a entrega pelo chat é só completa. Para vender só parte, é com a equipe |
| `409 { "reason": "already_delivered" }` | Já entregue (confirmação repetida). Tratar como **sucesso**: nada muda |
| `409 { "reason": "trade_not_negotiating" }` / `409 { "reason": "no_offer" }` | Importada / sem oferta |
| `404` | O supplier não tem trade com esse número |

## `POST /api/trades/{uuid}/message-sent` — acesso mandado

Registra que o supplier recebeu como entregar — o mesmo "Mensagem enviada" que a equipe marca na aba Trades. O chatbot chama ao mandar o modelo para colar as keys; a equipe marca ao mandar a página. Sem corpo; `204`. Repetir não muda nada. Trade inexistente ou apagada: `404`.

