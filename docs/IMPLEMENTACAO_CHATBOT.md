# Integração com o chatbot — plano de implementação

> **Documento temporário**, vivo enquanto a integração é implementada, e **versionado** para servir de referência em qualquer máquina. Nasceu do pedido escrito no repositório `chatbot-steam` em 2026-10-28 (commit `1a59826` deste repo) e foi revisado contra o código em 2026-10-29. Quando o último PR entrar, o contrato final migra para o doc de referência da integração e este arquivo é apagado. Se o código divergir deste texto, o código vence.

## Contexto

O chatbot conversa pela Steam com os Suppliers. Quando um Supplier manda uma lista ou um link do SteamTrades pelo chat, o chatbot pede ao price-cd que precifique. O price-cd manda os jogos ao Estoque, que **decide e grava a oferta** e cria a Trade. Depois o chatbot **lê a Trade no Estoque** para mostrar a oferta ao Supplier, com o link e o código da entrega.

Divisão de responsabilidades:

| Sistema | Responsável por |
|---|---|
| Sistema Estoque | Todos os dados da Trade, inclusive **quanto ofertar** em cada rodada: percentual, cotação, taxas, oferta máxima pelo `min_api` e o registro de cada oferta feita |
| Chatbot | A conversa com o Supplier: repassa o que ele pediu e mostra a oferta pronta. Não calcula nem guarda valor |
| price-cd | Precificar os jogos e avisar o chatbot do resultado por webhook |

Regras do chatbot que valem aqui:

- O chatbot **nunca guarda cópia** da Trade: lê sempre do Estoque, para não haver duas versões.
- As rotas de leitura são **rotas comuns do Estoque**, não rotas "do chatbot": o chatbot é só um consumidor delas.
- O Estoque **não chama o chatbot**. Quem avisa o fim da precificação é o price-cd, por webhook (ADR 0003 do `chatbot-steam`).
- Ids que cruzam sistemas são **uuid**; ids sequenciais ficam internos a cada sistema.

## Fluxos

Do mais comum para o mais raro:

| Caso | Estoque | price-cd → chatbot (webhook) | Chatbot → Supplier |
|---|---|---|---|
| 1. Há jogo a ofertar | Cria a Trade e a **primeira oferta**, no percentual da categoria do Supplier, e responde `201` com o `uuid` | Trade criada, com o `uuid` | Lê `GET /api/trades/{uuid}` e mostra a oferta, o link e o código |
| 2. Nenhum jogo nos interessa (nada lucrativo, ou tudo encalhado/em giveaway) | **Não cria Trade** e responde `200 { "created": false }` | `no_trade` | "Unfortunately none of these games fit…" |
| 3. Supplier pede mais | O chatbot repassa o total pedido; o Estoque decide e grava uma **nova oferta** (no máximo 3 no total), sem passar da oferta máxima de nenhuma linha | — (chamada direta do chatbot) | Mostra a oferta nova, ou avisa que essa já é a oferta final |
| 4. Supplier aceita | Nada muda na oferta: o valor acertado entra em `trades.amount` pela página da entrega, como hoje (ADR 0008) | — | Manda o link e o código |

## Decisões tomadas

| # | Tema | Decisão | Por quê |
|---|---|---|---|
| D1 | Identidade da Trade | Renomear `delivery_uuid` para `uuid`. Os valores não mudam, então os links já enviados continuam válidos (`/deliveries/{uuid}`) | A entrega é só uma rota; o uuid passa a ser a identidade da Trade entre os sistemas |
| D2 | Filtro na criação | Jogo encalhado ou em giveaway **não é ofertado**, como na prospecção (ADR 0013 e 0014). Continua virando linha marcada na Trade | Mesma regra de oferta em todo canal |
| D3 | Nada a ofertar | Não cria Trade, mas **responde** de forma explícita e distinta de erro, para o price-cd avisar o chatbot (caso 2) | O chatbot precisa responder ao Supplier |
| D4 | Moeda | Aceita `currency` (`tf2`, `eur`, `usd`; padrão `tf2`). **Sem cotação da TF2 na moeda, a Trade nasce em TF2** | Diferente da prospecção (que falha com 503): aqui o Supplier já está adicionado na Steam, e sabemos que ele aceita pelo menos uma das moedas, normalmente TF2 |
| D5 | Código da entrega | **O chatbot recebe o código da entrega pela API** | É ele quem manda link e código ao Supplier. Muda o modelo de ameaça do ADR 0008, por isso pede ADR novo (ver "Segurança") |
| D6 | Quem decide a oferta | **O Estoque**, em toda rodada. O chatbot só recebe a oferta pronta | Centraliza os dados e a lógica da Trade num lugar só, em vez de espalhar a regra de oferta entre os sistemas |
| D7 | Como a oferta é guardada | **Tabela própria e imutável** (`trade_offers` + `trade_offer_lines`): cada rodada é um registro novo, e a vigente é a de rodada mais alta | Uma oferta enviada é um fato com a cotação daquele momento, não um estado a recalcular (mesmo critério do ADR 0005). Colunas em `trade_lines` sobrescreveriam o que o Supplier já leu na primeira renegociação |
| D8 | Renegociação | **Existe, com no máximo 3 ofertas**: a inicial e duas contrapropostas (`SupplierOffer::MAX_ROUNDS`). A 3ª é a **final**, seja qual for o valor dela | Mais rodadas ficam repetitivas para o Supplier. E a final **não** é "chegou à oferta máxima": se fosse, o Supplier que insiste sempre arrancaria o menor lucro e ainda aprenderia onde ele fica |
| D9 | Percentual da primeira oferta | **Definido pela categoria do Supplier** (tabela abaixo). Padrão: 70% | A categoria já é o que diferencia os Suppliers; o percentual acompanha |
| D10 | Oferta máxima de cada linha | Calculada no backend **a partir de `MinimumMarginPolicy`**, a mesma régua que a aba Trades projeta no frontend, e não uma cópia dela | Uma régua só para "lucro abaixo do mínimo" em todo o sistema |
| D11 | Endpoint da criação | **Endpoint próprio**, `POST /api/trades`, com UseCase próprio. O `from-price-researcher` fica como está | Ver "Endpoint da criação", abaixo |
| D12 | Forma da renegociação | **Total pedido** (forma A): o chatbot repassa o total que o Supplier pediu, e o Estoque decide | É como a conversa acontece ("I want 5 keys"), e quem aceita ou limita continua sendo o Estoque. Regras em "Renegociação", abaixo, com pontos ainda a definir |
| D13 | Supplier bloqueado | Não cria Trade e responde como no caso 2 (`200 { "created": false }`), **sem dizer o motivo** | O chatbot responderia "none of these games fit" do mesmo jeito, e dizer ao Supplier que ele está bloqueado não é decisão do chatbot. A equipe vê o bloqueado na aba de Suppliers |
| D14 | `initial_offer_pct` | **Removido** (PR 0). O percentual vem só da categoria | Nenhum código o lia, e mantê-lo deixaria duas fontes para o mesmo número |
| D15 | O que a oferta grava | **Só o que não se recalcula:** por jogo, o valor lido (`price`); na oferta, a rodada, a moeda, o lucro, a cotação da TF2 (`tf2_rate`) e o pedido do Supplier. Final e total são calculados | A oferta é por jogo; o total é soma e o "final" é a rodada. Guardar o que se calcula só abre espaço para os dois divergirem |
| D16 | Autenticação dos serviços | **Um segredo só** (`EXTERNAL_SECRET`) para o price-cd e o chatbot, conferido pelo `VerifySecret` | Os três sistemas rodam na mesma VPS, sob o mesmo dono. Custo aceito: um vazamento em qualquer um abre as rotas de todos, e revogar exige trocar o segredo nos três — o que pesa mais a partir do PR 4, quando a API devolver o código da entrega |

### Categorias de Supplier e percentual inicial (D9)

O percentual é de **lucro** sobre o income líquido da Gamivo, o mesmo das colunas da aba Trades (`OfferCalculator::tf2Offer`: oferta = income líquido / (1 + lucro) / cotação da TF2). Lucro maior quer dizer oferta menor.

| Categoria | Valor no enum (`SupplierCategory`) | Rótulo na tela | Lucro da primeira oferta | Estado no código |
|---|---|---|---|---|
| Normal | nenhum (`category` nula) | Normal | **70%** (`NORMAL_INITIAL_PROFIT_PERCENT` = `OfferCalculator::NEW_SUPPLIER_PROFIT_PERCENT`, o padrão) | Já existe: é o Supplier sem categoria |
| VIP | `vip` | VIP | **80%** | Já existe a categoria; o percentual entrou no PR 0 |
| Varejo | `retail` | Varejo | **60%** | Criada no PR 0 |
| Bloqueado | `blocked` | Bloqueado | — **não recebe oferta** (D13) | Já existe a categoria |

Notas:

- Os percentuais são constantes `public const` do próprio enum, uma por categoria, lidas por `SupplierCategory::initialProfitPercent(?SupplierCategory)`. A categoria nula resolve para o Normal, e o `Blocked` devolve `null` (não recebe oferta).
- O Supplier que chega pelo chatbot e ainda não existe no Estoque é criado sem categoria (`SupplierService::resolveBySteamId`), então cai em **Normal**, com 70%.
- **O percentual só vale para a primeira oferta.** Na renegociação, quem limita é a oferta máxima (D10), que é o mesmo para todas as categorias.

## Endpoint da criação (D11)

O `POST /trades/from-price-researcher` **também é o callback das listas do SteamTrades e da pesquisa de bundle** (`docs/PRICE_RESEARCHER.md`). A pesquisa de bundle é frouxa de propósito (o bundle é precificado inteiro, sem cortar encalhado nem jogo barato). Colocar nele o filtro de D2 e a resposta `created: false` de D3 mudaria esses dois fluxos.

Por isso a Trade que nasce com oferta tem endpoint próprio, `POST /api/trades`, chamado pelo price-cd quando a precificação veio do chatbot (**implementado no PR 3**: `CreateTradeWithOfferUseCase`; contrato vivo em [`PRICE_RESEARCHER.md`](PRICE_RESEARCHER.md#trade-com-oferta--post-apitrades)). **Muda o que o price-cd chama, então precisa ser combinado com quem cuida dele.** O cálculo de oferta e o filtro saem de uma peça compartilhada com o `ProspectSupplierUseCase`, para as duas não divergirem.

## Oferta máxima de cada linha (D10)

> **Nome:** no glossário, "teto" é o `max_api` da Gamivo. Por isso o limite da oferta se chama **oferta máxima** (`maxTf2Offer`, `is_at_max`), também no `CONTEXT.md`.

A oferta máxima é o **maior valor que o Estoque aceita pagar por uma linha** sem que o lucro fique abaixo da margem inicial exigida pelo `min_api`.

A margem exigida depende do **custo**, e o custo é a própria oferta. Por isso a oferta máxima não é uma conta direta: é a maior oferta `c` em que `income líquido / c - 1 ≥ margem(c)`. Como a margem só muda nas faixas de custo de `MinimumMarginPolicy`, basta testar uma faixa por vez:

| Faixa de custo (€) | Margem exigida hoje | Oferta candidata |
|---|---|---|
| 1 a 10 (padrão) | 50% (`DEFAULT_MARGIN`) | income / 1,50 |
| abaixo de 1 | 55% (`LOW_COST_MARGIN`) | income / 1,55 |
| acima de 10 até 15 | 45% (`HIGH_COST_MARGIN`) | income / 1,45 |
| acima de 15 | 40% (`VERY_HIGH_COST_MARGIN`) | income / 1,40 |

A candidata só vale se cair dentro da própria faixa, e a oferta máxima é a maior candidata válida. Depois ela é convertida para TF2 e para a moeda da Trade com a cotação do momento.

O canal da Trade do chatbot é `supplier_trade`, então a margem de compra direta (`BUNDLE_STORE_MARGIN`) não se aplica aqui.

**Implementado (PR 2)** em `MinimumMarginPolicy::maxInitialCost(float $netIncome, PurchaseChannel $channel)`, que reusa as faixas privadas da própria policy. A oferta por linha sai de `App\Domain\Pricing\SupplierOffer::line()`, a mesma peça que a prospecção passou a usar.

## Renegociação (D12)

### Regras definidas

| Regra | Como funciona |
|---|---|
| **Número de rodadas** | Até 3 ofertas por Trade: rodada 1 (inicial), 2 e 3. A rodada fica gravada (`trade_offers.round`), única por Trade |
| **Oferta final** | A da rodada 3 (`SupplierOffer::isFinalRound`). Depois dela, um novo pedido responde `409` |
| **Oferta máxima** | Nenhuma linha passa dela, em rodada nenhuma (D10). É limite de segurança, **não** marca o fim da negociação |
| **Entrada do pedido** | O total que o Supplier pediu (`requested_total`), na moeda da Trade (R4.4) |
| **Pedido abaixo do vigente** | Pedido menor ou igual à oferta vigente não é pedido de mais: responde `422`, e aceitar é pela página da entrega (caso 4) |

**Distribuição entre as linhas.** Quando o Estoque oferta um total novo, cada linha sobe na **mesma proporção** em relação à oferta vigente. A linha que chega à oferta máxima para nela, e o que faltaria a ela é redistribuído entre as outras, na mesma proporção, até o total fechar. Assim a ordem relativa das linhas se mantém e nenhuma passa da oferta máxima.

**Arredondamento.** Cada linha é arredondada a 2 casas (`OfferCalculator::MONEY_DECIMALS`), nunca abaixo de `MIN_CASH_OFFER`. Os centavos que sobram ou faltam para o total bater vão para as linhas com maior folga até a oferta máxima. O total da oferta é sempre a soma das linhas já arredondadas, como na prospecção.

### Pontos a definir

| # | Pergunta | Recomendação |
|---|---|---|
| R4.6 | **Quanto o Estoque cede em cada rodada.** Com só "oferta exatamente o pedido, ou a máxima se passar", o Supplier que pede muito recebe a oferta máxima já na rodada 2 — e aprende onde ela fica | Cada rodada tem um **lucro-alvo**, que desce um degrau fixo a partir do lucro da categoria (constante no Domain; ex.: 10 pontos). A oferta da rodada é **o menor** entre o pedido e o valor no lucro-alvo da rodada, com cada linha limitada à oferta máxima. Com 10 pontos: VIP 80 → 70 → 60%, Normal 70 → 60 → 50%, Varejo 60 → 50 → 40% (o que ficar abaixo da margem do `min_api` para na oferta máxima). **Os degraus são decisão do negócio** |
| R4.7 | **Não há o que ceder antes da rodada 3.** Ex.: Varejo com jogos baratos chega à oferta máxima na rodada 2 | Responder que a oferta vigente já é a melhor (`409`, como a final), sem gravar uma rodada igual à anterior |
| R4.2 | **A cotação mudou entre as rodadas.** As ofertas máximas são recalculadas com a cotação do momento, e podem ficar abaixo de uma linha já ofertada | A nova oferta nunca fica **abaixo** da vigente, linha a linha: a linha cuja oferta máxima caiu mantém o valor vigente |
| R4.3 | **O Supplier quer vender só parte da lista.** Ex.: aceita 3 dos 5 jogos pelo valor ofertado | Fora do escopo da primeira versão: ele aceita a oferta e entrega só as linhas que quiser pela página da entrega, onde linha em branco já é resposta legítima (ADR 0008) |
| R4.4 | **Pedido em outra moeda.** A Trade está em TF2 e o Supplier pede em euro | O chatbot repassa só na moeda da Trade; converter é decidir valor. Se a moeda precisar mudar, é outra Trade |
| R4.5 | **O Supplier aceita uma oferta antiga.** Ex.: aceita a primeira, depois de duas rodadas | Vale sempre a vigente: o chatbot só mostra a vigente, e a página da entrega registra o `amount` que ele informar |

## Armazenamento da oferta (D7)

**`trade_offers`**: uma linha por oferta feita. Imutável: uma nova rodada cria um registro novo.

| Coluna | Para quê |
|---|---|
| `id`, `trade_id` | |
| `round` | 1 a 3 (`SupplierOffer::MAX_ROUNDS`); único por Trade, o que também impede gravar a primeira oferta duas vezes |
| `profit_percent` | Percentual de lucro efetivo da oferta: o da categoria na primeira, o que resulta do total aceito nas seguintes |
| `currency` | Moeda da oferta inteira, já com o fallback para TF2 (D4). As linhas não repetem a moeda |
| `tf2_rate` | Quanto vale 1 TF2 key na moeda da oferta, no momento (1 em TF2). É o único dado da oferta que não se recalcula depois — o sistema só guarda a cotação atual — e é o que explica o valor de cada jogo |
| `requested_total` | O que o Supplier pediu para provocar a rodada; nula na primeira oferta |
| `created_at` | Quando foi feita. **A oferta vigente é a de rodada mais alta** |

**Calculado, sem coluna** (D15): a oferta é **final** quando `round` é a última (`TradeOffer::isFinal()`), e o **total** é a soma dos `price` das linhas, já arredondados (`TradeOffer::totalPrice()`).

**`trade_offer_lines`**: o valor de cada jogo ofertado. Jogo não ofertado (encalhado, giveaway) simplesmente não tem linha aqui.

| Coluna | Para quê |
|---|---|
| `id`, `trade_offer_id`, `trade_line_id` | A linha da Trade que está sendo ofertada (nula se a equipe apagar a linha depois) |
| `game_name`, `region` | **Cópia** do que o Supplier leu: ele edita a região na página da entrega, e a oferta tem de continuar dizendo o que foi ofertado |
| `price` | O que o Supplier leu por este jogo, na moeda da oferta, arredondado e nunca abaixo de 0,01 |

A oferta exata em TF2 e o limite da oferta máxima só importam **durante o cálculo**: a rodada seguinte parte do `price` lido, recalculando a oferta máxima com a cotação do momento.

Mais três pontos sobre o desenho:

- **O valor acertado continua em `trades.amount`.** A oferta é o que propomos; o `amount` é o que fechamos, e ele é informado na página da entrega (ADR 0008).
- **A entrega nunca escreve nessas tabelas.** `trade_lines` recebe escrita do Supplier pela página da entrega; a oferta é escrita só pelo Estoque, numa tabela à parte.
- **A aba Trades mantém as colunas 100/80/60%** como ferramenta da equipe, e passa a mostrar também a oferta enviada, lida dessas tabelas.

## Contrato proposto

### 1. `POST /api/trades` — criar Trade com a primeira oferta (price-cd)

Corpo: o mesmo do callback atual (`supplier_steam_id`, `list_code`, `title`, `games[]` com `name`, `market_price_euro`, `popularity`, `region`, `gamivo_id`) mais `currency`. Aqui o `supplier_steam_id` é obrigatório: o chatbot sempre sabe com quem fala.

| Resultado | Resposta |
|---|---|
| Há jogo a ofertar | `201 { "uuid": "…", "created_at": "…", "currency": "eur" }`. A `currency` é a efetiva: `tf2` quando não havia cotação na moeda pedida (D4) |
| Nada a ofertar, ou Supplier bloqueado (D13) | `200 { "created": false }` |
| Corpo inválido | `422` |
| Sem token | `401` |

### 2. `GET /api/trades/{uuid}` — ler a Trade com a oferta vigente (chatbot)

> **Implementado no PR 4.** Contrato vivo em [`CHATBOT.md`](CHATBOT.md), que vale sobre esta proposta. Diferenças: a oferta traz também `round`; a lista vem em `{ "trades": [...] }`, com `games_count` e `total_price`; caminho desconhecido em `/api` responde `404` em JSON.

| Campo | Observação |
|---|---|
| `uuid`, `created_at`, `currency` | |
| `state` | `negotiating`, `awaiting_review` ou `imported` (`TradeDeliveryState`, que já existe) |
| `offer.lines[]` | Só as linhas da oferta vigente: `game_name`, `region` (`""` = global), `price` na moeda da Trade |
| `offer.total_price` | Soma das linhas já arredondadas (`TradeOffer::totalPrice()`) |
| `offer.is_final` | É a última rodada: não há contraproposta depois dela (D8, `TradeOffer::isFinal()`) |
| `offer.created_at` | Quando a oferta vigente foi feita |
| `delivery_url`, `delivery_code` | URL completa de `/deliveries/{uuid}` e o código (D5) |

**Nunca sai:** preço de mercado, popularidade, Gamivo ID, margem, percentual de lucro, categoria do Supplier, ofertas máximas, linhas encalhadas ou em giveaway, `key_code`, ofertas anteriores. A projeção seleciona as colunas à mão (`select()` explícito) e molda a saída num Resource próprio, como o `DeliveryReadModel` do ADR 0008. Trade apagada (soft delete, ADR 0011) responde `404`.

### 3. `GET /api/suppliers/{steam_id}/trades` — Trades abertas do Supplier (chatbot)

- Só Trades em `negotiating` (entregue ou importada fica de fora).
- Cada item: `uuid`, `created_at`, `currency`, quantidade de jogos e total da oferta vigente.
- Da mais recente para a mais antiga.
- **Sem o código da entrega**: quem quiser o código lê a Trade pela rota 2. A listagem não vira um atalho para colher os códigos de todas as Trades de uma vez.

### 4. `POST /api/trades/{uuid}/offers` — pedir nova oferta (chatbot)

Corpo: `{ "requested_total": 5.0 }`, na moeda da Trade. Regras em "Renegociação".

| Resultado | Resposta |
|---|---|
| Nova oferta gravada | `201`, com a Trade no formato da rota 2 |
| A oferta vigente já é final (rodada 3), ou não há o que ceder (R4.7) | `409 { "reason": "offer_is_final" }`, sem gravar nada |
| Trade fora de `negotiating` | `409 { "reason": "trade_not_negotiating" }` |
| Pedido menor ou igual à oferta vigente (ou abaixo do aumento mínimo, se R4.1 for adotado) | `422` |

### 5. `POST /api/trades/{uuid}/message-sent` — marcar mensagem enviada (chatbot)

Marca o `message_sent` que a aba já tem. O ADR 0008 diz que ele é "marcado por quem mandou", e quem manda passa a ser o chatbot. Opcional; resposta `204`.

### Autenticação

Um **segredo só** para todos os consumidores (D16): o `VerifySecret` confere o mesmo `EXTERNAL_SECRET` que o price-cd já usa, e o chatbot recebe esse mesmo segredo. As rotas ficam em `routes/api.php`, sem sessão nem cookie (implementado no PR 3).

> **Rede Docker — a decidir antes do PR 4 (R8).** Hoje o nginx entrega `/api/*` pela internet como o resto do site, e só o token protege. Restringir `/api/` à rede Docker (`allow` da sub-rede de `sistema-estoque-cd` no `default.conf`) fecha a porta a quem não está na VPS — o que pesa mais quando o PR 4 passar a devolver o código da entrega. Depende da URL que o price-cd usa para chamar o Estoque (`SISTEMA_ESTOQUE_URL`): se for o domínio público, a restrição o bloquearia também. Lembrar que o deploy não recarrega o nginx ([`agents/deploy.md`](agents/deploy.md)).

Quem chama cada rota (todas com o mesmo segredo):

| Rota | price-cd | chatbot |
|---|---|---|
| `POST /api/trades` | ✔ | |
| `GET /api/trades/{uuid}`, `GET /api/suppliers/{steam_id}/trades`, `POST /api/trades/{uuid}/offers`, `POST /api/trades/{uuid}/message-sent` | | ✔ |

## Segurança

Hoje só a equipe logada vê o código da entrega. Com D5, **quem tiver o `EXTERNAL_SECRET` — o segredo de todos os serviços (D16) — lê a credencial de toda Trade aberta**, e a página de entrega exibe os `key_code` já preenchidos até o import. A contenção que resta é a mesma do ADR 0008 (a credencial morre no import), mais:

- O código só sai na leitura de **uma** Trade pelo uuid, nunca na listagem (rota 3).
- O segredo é o mesmo de todos os serviços (D16): vazou em qualquer sistema, troca-se nos três.
- Restringir `/api/*` à rede Docker no nginx (R8, em "Autenticação").

Essa decisão está no [ADR 0015](adr/0015-chatbot-reads-the-delivery-credential-through-the-api.md), que referencia o 0008. A guarda das ofertas como registro imutável (D7) entra como **ADR 0016**.

## PRs

| # | PR | Depende de | Estado |
|---|---|---|---|
| 0 | Categoria **Varejo** (`retail`) no `SupplierCategory`, no seletor da aba de Suppliers e no `CONTEXT.md`; percentual inicial por categoria (D9) no Domain; remoção do `initial_offer_pct` (D14) | nada | feito (#117) |
| 1 | Renomear `delivery_uuid` para `uuid` (D1): coluna, rota da entrega, model, `DeliveryCredential`, testes e docs, incluindo os ADRs 0008 e 0011 | nada | feito (#118) |
| 2 | Oferta no backend (D6, D7, D10): tabelas `trade_offers` e `trade_offer_lines`, oferta máxima pública em `MinimumMarginPolicy` (`maxInitialCost`), cálculo da primeira oferta no Domain, peça compartilhada com a prospecção, ADR 0016 | 0 | feito (#119) |
| 3 | `POST /api/trades` com filtro, moeda com fallback, primeira oferta e resposta `created: false` (D2, D3, D4, D11, D13); `routes/api.php` | 1, 2 | feito (#120) |
| 4 | Leituras (rotas 2 e 3) com o código da entrega + ADR 0015 (D5) | 3 | em revisão |
| 5 | Renegociação (rota 4, D12 com os pontos R4.x fechados — o R4.6 decide os degraus) e mensagem enviada (rota 5) | 4 | a fazer |
| 6 | Aba Trades mostra a oferta enviada e o histórico de rodadas | 2 | a fazer |

O PR 3 destrava o webhook do price-cd: é ele que devolve o `uuid` que o price-cd repassa ao chatbot.

## Critérios de aceite

- Criar uma Trade pelo price-cd devolve o `uuid` e grava a primeira oferta. Quando nada é ofertável, ou o Supplier está bloqueado, a resposta é distinta e não é erro, e nada é gravado.
- Jogo encalhado ou em giveaway não entra na oferta, mas continua como linha marcada na Trade.
- A Trade criada com `currency: eur` tem a oferta em euro. Sem `currency`, ou sem cotação da TF2 em euro, ela sai em TF2.
- A primeira oferta usa o lucro da categoria: VIP 80%, Normal (sem categoria) 70%, Varejo 60%. Supplier novo cai em Normal.
- A oferta máxima de cada linha sai de `MinimumMarginPolicy`, sem cópia das faixas fora dela.
- Cada rodada de renegociação cria uma oferta nova; as anteriores não mudam.
- Uma Trade tem no máximo 3 ofertas; a 3ª é a final, seja qual for o valor, e um novo pedido depois dela responde `409`.
- A primeira oferta não pode ser gravada duas vezes (rodada única por Trade).
- Nenhuma oferta passa da oferta máxima de qualquer linha.
- O `from-price-researcher` continua igual para as listas e para a pesquisa de bundle.
- Os links de entrega já enviados continuam funcionando depois do rename.
- A leitura pelo uuid traz a oferta vigente (jogos, região, valores, total), a moeda, o link e o código, e nenhum campo interno nem oferta anterior.
- A listagem traz só as Trades em negociação, da mais recente para a mais antiga, e nenhum código.
- Sem o segredo, ou com um errado, as rotas respondem `401`.
