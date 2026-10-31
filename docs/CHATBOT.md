# Chatbot — referência de integração

O chatbot (`chatbot-steam`) conversa pela Steam com os suppliers. Ele **não guarda cópia** de trade: lê do Sistema Estoque a oferta e o acesso à entrega, repassa as contrapropostas do supplier, e o Estoque nunca o chama. Quem avisa o chatbot do fim de uma precificação é o price-researcher, por webhook, depois de criar a trade em `POST /api/trades` (contrato em [`PRICE_RESEARCHER.md`](PRICE_RESEARCHER.md#trade-com-oferta--post-apitrades)).

As regras da oferta estão em [`PRODUCT.md`](PRODUCT.md#oferta-ao-supplier); as decisões de desenho, nos ADRs [0015](adr/0015-chatbot-reads-the-delivery-credential-through-the-api.md) e [0016](adr/0016-supplier-offers-recorded-as-immutable-records.md); o que falta, em [`IMPROVEMENTS.md`](IMPROVEMENTS.md).

## Fluxos

Do mais comum para o mais raro:

| Caso | Estoque | price-cd → chatbot (webhook) | Chatbot → supplier |
|---|---|---|---|
| 1. Há jogo a ofertar | Cria a trade com a **primeira oferta** (lucro da categoria do supplier) e responde `201` com o `uuid` | Trade criada, com o `uuid` | Lê `GET /api/trades/{uuid}` e mostra a oferta, o link e o código |
| 2. Nenhum jogo interessa (nada lucrativo, tudo encalhado ou em giveaway, ou supplier bloqueado) | **Não cria trade** e responde `200 { "created": false }` | `no_trade` | "Unfortunately none of these games fit…" |
| 3. Supplier pede mais | Decide e grava a próxima rodada (no máximo 3 ofertas) | — (o chatbot chama `POST /api/trades/{uuid}/offers`) | Mostra a oferta nova, ou avisa que a vigente já é a final |
| 4. Supplier aceita | A oferta não muda: o valor acertado entra em `trades.amount` pela página da entrega | — | Manda link e código; marca `message-sent` |

## Casos de conversa

| Situação | Como tratar |
|---|---|
| O supplier quer vender **só parte** da lista | Ele aceita a oferta e entrega só os jogos que quiser pela página da entrega — linha em branco é resposta legítima ([ADR 0008](adr/0008-supplier-fills-trade-through-tokenised-link.md)) |
| O supplier pede **em outra moeda** | O pedido vai sempre na moeda da trade; converter é decidir valor, e quem decide é o Estoque. Moeda diferente é outra trade |
| O supplier aceita uma **oferta antiga** | Vale sempre a vigente: o chatbot só mostra a vigente, e a página da entrega registra o `amount` que ele informar |
| O supplier pede mais por **um jogo só** | A contraproposta recebe um **total**, distribuído entre os jogos na proporção da oferta vigente — não um valor por jogo ([`PRODUCT.md`](PRODUCT.md#oferta-ao-supplier)) |

## Autenticação e rede

| Item | Como é |
|---|---|
| Token | `Authorization: Bearer <EXTERNAL_SECRET>` — o mesmo segredo de todos os serviços (`VerifySecret`); sem ele ou errado, `401` |
| Rotas | `routes/api.php`, prefixo `/api`, sem sessão nem cookie |
| Caminho desconhecido em `/api` | `404 { "message": "Not found." }`, nunca redirect |
| Ids | A trade é sempre o `uuid`; o supplier, o SteamID64. Uuid ou SteamID64 malformado é `404` no roteador |
| Datas | ISO 8601 com o fuso da aplicação (`APP_TIMEZONE`), em toda a API — ex.: `2026-10-29T22:38:22-03:00` |

## `GET /api/trades/{uuid}` — a trade com a oferta vigente

```json
{
  "uuid": "1fd5f585-5e5b-4f2e-9283-579aa744d82a",
  "created_at": "2026-10-29T22:38:22-03:00",
  "currency": "eur",
  "state": "negotiating",
  "offer": {
    "round": 1,
    "is_final": false,
    "created_at": "2026-10-29T22:38:22-03:00",
    "total_price": 4.16,
    "lines": [
      { "game_name": "Black Book", "region": "", "price": 0.96 },
      { "game_name": "Portal 2", "region": "EU", "price": 3.20 }
    ]
  },
  "delivery_url": "https://…/deliveries/1fd5f585-5e5b-4f2e-9283-579aa744d82a",
  "delivery_code": "ABCD-EFGH-JKMN-PQRS"
}
```

| Campo | Observação |
|---|---|
| `currency` | Moeda da trade (e da oferta): `tf2`, `eur` ou `usd` |
| `state` | `negotiating`, `awaiting_review` (entregue, esperando a equipe) ou `imported` |
| `offer` | A oferta vigente — a de rodada mais alta. `null` quando a trade não tem oferta (criada por outro caminho) |
| `offer.lines[]` | Só os jogos ofertados: `game_name`, `region` (`""` = key global) e `price` na moeda da trade. Jogo encalhado ou em giveaway não aparece |
| `offer.total_price` | Soma dos `price`, já arredondados |
| `offer.is_final` | Última rodada (a 3ª): não há contraproposta depois dela |
| `delivery_url`, `delivery_code` | O acesso à entrega para mandar ao supplier. `null` depois do import, quando a credencial deixa de valer ([ADR 0015](adr/0015-chatbot-reads-the-delivery-credential-through-the-api.md)) |

**Nunca sai:** preço de mercado, popularidade, Gamivo ID, lucro, categoria do supplier, oferta máxima, jogo deixado fora da oferta, `key_code`, ofertas anteriores. Trade inexistente ou apagada responde `404`.

## `GET /api/suppliers/{steamId}/trades` — trades abertas de um supplier

```json
{
  "trades": [
    { "uuid": "…", "created_at": "…", "currency": "tf2", "games_count": 2, "total_price": 5.8 }
  ]
}
```

- Só trades **em negociação**: entregue ou importada fica de fora.
- Da mais recente para a mais antiga.
- `games_count` e `total_price` são da oferta vigente (`0` e `null` sem oferta).
- **Sem acesso à entrega:** para o link e o código, ler a trade pelo `uuid`.
- Supplier sem trades abertas, ou desconhecido: `200` com a lista vazia.

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
| `422 { "reason": "not_an_increase" }` | O pedido não passa da oferta vigente — aceitar é pela página da entrega |
| `422` com `errors` | `requested_total` ausente, zero, negativo ou com mais de 2 casas |
| `404` / `503` | Trade inexistente / sem cotação da TF2 para recalcular. Nada é gravado |

O chatbot nunca calcula: mostra ao supplier a oferta que voltou. A nova rodada pode ser **menor que o pedido** (quando passa do que o Estoque cede nesta rodada) ou **exatamente o pedido**.

## `POST /api/trades/{uuid}/message-sent` — acesso mandado

Registra que o chatbot mandou link e código ao supplier — o mesmo "Mensagem enviada" que a equipe marca na aba Trades. Sem corpo; `204`. Repetir não muda nada. Trade inexistente ou apagada: `404`.

