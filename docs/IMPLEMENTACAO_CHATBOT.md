# Integração com o chatbot — plano de implementação

> **Documento temporário**, vivo enquanto a integração é implementada, e **versionado** para servir de referência em qualquer máquina. Nasceu do pedido escrito no repositório `chatbot-steam` em 2026-10-28 (commit `1a59826` deste repo) e foi revisado contra o código em 2026-10-29. Quando o último PR entrar, o contrato final migra para o doc de referência da integração e este arquivo é apagado. Se o código divergir deste texto, o código vence.

## Contexto

O chatbot conversa pela Steam com os Suppliers. Quando um Supplier manda uma lista ou um link do SteamTrades pelo chat, o chatbot pede ao price-cd que precifique. O price-cd manda os jogos ao Estoque, que **decide e grava a oferta** e cria a Trade. Depois o chatbot **lê a Trade no Estoque** para mostrar a oferta ao Supplier, com o link e o código da entrega.

Divisão de responsabilidades:

| Sistema | Responsável por |
|---|---|
| Sistema Estoque | Todos os dados da Trade, inclusive **quanto ofertar** em cada rodada: percentual, cotação, taxas, teto do `min_api` e o registro de cada oferta feita |
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
| 3. Supplier pede mais | O chatbot repassa o total pedido; o Estoque decide e grava uma **nova oferta**, sem passar do teto de nenhuma linha | — (chamada direta do chatbot) | Mostra a oferta nova, ou avisa que essa já é a oferta final |
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
| D7 | Como a oferta é guardada | **Tabela própria e imutável** (`trade_offers` + `trade_offer_lines`): cada rodada é um registro novo, e a vigente é a mais recente | Uma oferta enviada é um fato com a cotação daquele momento, não um estado a recalcular (mesmo critério do ADR 0005). Colunas em `trade_lines` sobrescreveriam o que o Supplier já leu na primeira renegociação |
| D8 | Renegociação | **Existe**: o Supplier pode pedir mais, e o Estoque responde com uma nova oferta ou com "essa é a final" | — |
| D9 | Percentual da primeira oferta | **Definido pela categoria do Supplier** (tabela abaixo). Padrão: 70% | A categoria já é o que diferencia os Suppliers; o percentual acompanha |
| D10 | Teto de cada linha | Calculado no backend **a partir de `MinimumMarginPolicy`**, a mesma régua que a aba Trades projeta no frontend, e não uma cópia dela | Uma régua só para "lucro abaixo do mínimo" em todo o sistema |
| D11 | Endpoint da criação | **Endpoint próprio**, `POST /api/trades`, com UseCase próprio. O `from-price-researcher` fica como está | Ver "Endpoint da criação", abaixo |
| D12 | Forma da renegociação | **Total pedido** (forma A): o chatbot repassa o total que o Supplier pediu, e o Estoque decide | É como a conversa acontece ("I want 5 keys"), e quem aceita ou limita continua sendo o Estoque. Regras em "Renegociação", abaixo, com pontos ainda a definir |
| D13 | Supplier bloqueado | Não cria Trade e responde como no caso 2 (`200 { "created": false }`), **sem dizer o motivo** | O chatbot responderia "none of these games fit" do mesmo jeito, e dizer ao Supplier que ele está bloqueado não é decisão do chatbot. A equipe vê o bloqueado na aba de Suppliers |
| D14 | `initial_offer_pct` | **Removido** (PR 0). O percentual vem só da categoria | Nenhum código o lia, e mantê-lo deixaria duas fontes para o mesmo número |

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
- **O percentual só vale para a primeira oferta.** Na renegociação, quem limita é o teto do `min_api` (D10), que é o mesmo para todas as categorias.

## Endpoint da criação (D11)

O `POST /trades/from-price-researcher` **também é o callback das listas do SteamTrades e da pesquisa de bundle** (`docs/PRICE_RESEARCHER.md`). A pesquisa de bundle é frouxa de propósito (o bundle é precificado inteiro, sem cortar encalhado nem jogo barato). Colocar nele o filtro de D2 e a resposta `created: false` de D3 mudaria esses dois fluxos.

Por isso a Trade que nasce com oferta tem endpoint próprio, `POST /api/trades`, chamado pelo price-cd quando a precificação veio do chatbot. **Muda o que o price-cd chama, então precisa ser combinado com quem cuida dele.** O cálculo de oferta e o filtro saem de uma peça compartilhada com o `ProspectSupplierUseCase`, para as duas não divergirem.

## Teto de cada linha (D10)

O teto é o **maior valor que o Estoque aceita pagar por uma linha** sem que o lucro fique abaixo da margem inicial exigida pelo `min_api`.

A margem exigida depende do **custo**, e o custo é a própria oferta. Por isso o teto não é uma conta direta: é a maior oferta `c` em que `income líquido / c - 1 ≥ margem(c)`. Como a margem só muda nas faixas de custo de `MinimumMarginPolicy`, basta testar uma faixa por vez:

| Faixa de custo (€) | Margem exigida hoje | Oferta candidata |
|---|---|---|
| 1 a 10 (padrão) | 50% (`DEFAULT_MARGIN`) | income / 1,50 |
| abaixo de 1 | 55% (`LOW_COST_MARGIN`) | income / 1,55 |
| acima de 10 até 15 | 45% (`HIGH_COST_MARGIN`) | income / 1,45 |
| acima de 15 | 40% (`VERY_HIGH_COST_MARGIN`) | income / 1,40 |

A candidata só vale se cair dentro da própria faixa, e o teto é a maior candidata válida. Depois ele é convertido para TF2 e para a moeda da Trade com a cotação do momento.

O canal da Trade do chatbot é `supplier_trade`, então a margem de compra direta (`BUNDLE_STORE_MARGIN`) não se aplica aqui.

**Para implementar:** hoje `initialMargin()` e `costTierMargin()` são `private` em `MinimumMarginPolicy`. O teto precisa de um método público na própria policy (ex.: `MinimumMarginPolicy::maxInitialCost(float $netIncome, PurchaseChannel $channel)`), e não de uma cópia das faixas em outro lugar.

## Renegociação (D12)

### Regras definidas

Entrada: a oferta vigente, o total pedido pelo Supplier (`requested_total`, na moeda da Trade) e o teto de cada linha ofertada.

| Situação | O que o Estoque faz | `is_final` |
|---|---|---|
| Pedido **cabe** na soma dos tetos | Grava uma oferta nova com **exatamente o total pedido**, distribuído entre as linhas (abaixo) | `false` |
| Pedido **passa** da soma dos tetos | Grava uma oferta nova com **todas as linhas no teto**: é o máximo que o Estoque paga | `true` |
| A oferta vigente já é final | Não grava nada e responde `409` | — |
| Pedido **menor ou igual** à oferta vigente | Não grava nada e responde `422`: aceitar é pela página da entrega (caso 4) | — |

**Distribuição entre as linhas.** Cada linha sobe na **mesma proporção** em relação à oferta vigente. A linha que bate no teto para nele, e o que faltaria a ela é redistribuído entre as outras, na mesma proporção, até o total fechar. Assim a ordem relativa das linhas se mantém e nenhuma passa do teto.

**Arredondamento.** Cada linha é arredondada a 2 casas (`OfferCalculator::MONEY_DECIMALS`), nunca abaixo de `MIN_CASH_OFFER`. Os centavos que sobram ou faltam para o total bater com o pedido vão para as linhas com maior folga até o teto. O total da oferta é sempre a soma das linhas já arredondadas, como na prospecção.

### Pontos a definir

| # | Pergunta | Recomendação |
|---|---|---|
| R4.1 | **Limite de rodadas.** Sem limite, o Supplier pode pedir 0,01 a mais várias vezes | Um aumento mínimo por rodada (ex.: 5% sobre o total vigente) ou um número máximo de rodadas, como constante no Domain. O mínimo por rodada é mais natural na conversa |
| R4.2 | **A cotação mudou entre as rodadas.** Os tetos são recalculados com a cotação do momento, e podem ficar abaixo de uma linha já ofertada | A nova oferta nunca fica **abaixo** da vigente, linha a linha. Se o teto caiu abaixo do que já foi ofertado, a linha mantém o valor vigente e a oferta é marcada como final |
| R4.3 | **O Supplier quer vender só parte da lista.** Ex.: aceita 3 dos 5 jogos pelo valor ofertado | Fora do escopo da primeira versão: ele aceita a oferta e entrega só as linhas que quiser pela página da entrega, onde linha em branco já é resposta legítima (ADR 0008) |
| R4.4 | **Pedido em outra moeda.** A Trade está em TF2 e o Supplier pede em euro | O chatbot repassa só na moeda da Trade; converter é decidir valor. Se a moeda precisar mudar, é outra Trade |
| R4.5 | **O Supplier aceita uma oferta antiga.** Ex.: aceita a primeira, depois de duas rodadas | Vale sempre a vigente: o chatbot só mostra a vigente, e a página da entrega registra o `amount` que ele informar |

## Armazenamento da oferta (D7)

**`trade_offers`**: uma linha por oferta feita. Imutável: uma nova rodada cria um registro novo.

| Coluna | Para quê |
|---|---|
| `id`, `trade_id` | |
| `profit_percent` | Percentual de lucro efetivo da oferta: o da categoria na primeira, o que resulta do total aceito nas seguintes |
| `currency` | Moeda efetiva da oferta, já com o fallback para TF2 (D4) |
| `tf2_price` | Cotação da TF2 na moeda, no momento da oferta. É o que explica o valor depois |
| `total_offer_price` | Soma das linhas já arredondadas |
| `requested_total` | O que o Supplier pediu; nula na primeira oferta |
| `is_final` | Todas as linhas estão no teto: não há rodada seguinte |
| `created_at` | Quando foi feita. **A oferta vigente é a mais recente** |

**`trade_offer_lines`**: o valor de cada jogo ofertado. Jogo não ofertado (encalhado, giveaway) simplesmente não tem linha aqui.

| Coluna | Para quê |
|---|---|
| `id`, `trade_offer_id`, `trade_line_id` | A linha da Trade que está sendo ofertada |
| `tf2_offer` | Oferta exata em TF2, sem arredondar, como na prospecção |
| `offer_price` | Valor na moeda da oferta, arredondado. É o que o Supplier lê |
| `is_capped` | A linha está no teto do `min_api` (D10) |

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

| Campo | Observação |
|---|---|
| `uuid`, `created_at`, `currency` | |
| `state` | `negotiating`, `awaiting_review` ou `imported` (`TradeDeliveryState`, que já existe) |
| `offer.lines[]` | Só as linhas da oferta vigente: `game_name`, `region` (`""` = global), `offer_price` na moeda da Trade |
| `offer.total_offer_price` | Soma das linhas já arredondadas |
| `offer.is_final` | Não há rodada seguinte (D12) |
| `offer.created_at` | Quando a oferta vigente foi feita |
| `delivery_url`, `delivery_code` | URL completa de `/deliveries/{uuid}` e o código (D5) |

**Nunca sai:** preço de mercado, popularidade, Gamivo ID, margem, percentual de lucro, categoria do Supplier, tetos, linhas encalhadas ou em giveaway, `key_code`, ofertas anteriores. A projeção seleciona as colunas à mão (`select()` explícito) e molda a saída num Resource próprio, como o `DeliveryReadModel` do ADR 0008. Trade apagada (soft delete, ADR 0011) responde `404`.

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
| A oferta vigente já é final | `409 { "reason": "offer_is_final" }`, sem gravar nada |
| Trade fora de `negotiating` | `409 { "reason": "trade_not_negotiating" }` |
| Pedido menor ou igual à oferta vigente (ou abaixo do aumento mínimo, se R4.1 for adotado) | `422` |

### 5. `POST /api/trades/{uuid}/message-sent` — marcar mensagem enviada (chatbot)

Marca o `message_sent` que a aba já tem. O ADR 0008 diz que ele é "marcado por quem mandou", e quem manda passa a ser o chatbot. Opcional; resposta `204`.

### Autenticação

Cada consumidor tem o seu token, para poder ser revogado sozinho: o price-cd segue com o atual (`services.external_secret`), e o chatbot ganha um próprio (`CHATBOT_API_TOKEN`, lido por `config()`). O `VerifySecret` passa a receber o nome do consumidor como parâmetro (`VerifySecret:chatbot`) em vez de conhecer um segredo só. As rotas ficam em `routes/api.php`, sem sessão nem cookie, acessíveis pela rede Docker `sistema-estoque-cd`.

| Rota | price-cd | chatbot |
|---|---|---|
| `POST /api/trades` | ✔ | |
| `GET /api/trades/{uuid}`, `GET /api/suppliers/{steam_id}/trades`, `POST /api/trades/{uuid}/offers`, `POST /api/trades/{uuid}/message-sent` | | ✔ |

## Segurança

Hoje só a equipe logada vê o código da entrega. Com D5, **quem tiver o `CHATBOT_API_TOKEN` lê a credencial de toda Trade aberta**, e a página de entrega exibe os `key_code` já preenchidos até o import. A contenção que resta é a mesma do ADR 0008 (a credencial morre no import), mais:

- O código só sai na leitura de **uma** Trade pelo uuid, nunca na listagem (rota 3).
- O token do chatbot é separado e revogável sozinho.
- As rotas só respondem na rede Docker, não pela internet.

Essa decisão entra como **ADR 0015** ("o chatbot lê a credencial de entrega pela API"), referenciando o 0008. A guarda das ofertas como registro imutável (D7) entra como **ADR 0016**.

## PRs

| # | PR | Depende de | Estado |
|---|---|---|---|
| 0 | Categoria **Varejo** (`retail`) no `SupplierCategory`, no seletor da aba de Suppliers e no `CONTEXT.md`; percentual inicial por categoria (D9) no Domain; remoção do `initial_offer_pct` (D14) | nada | feito (#117) |
| 1 | Renomear `delivery_uuid` para `uuid` (D1): coluna, rota da entrega, model, `DeliveryCredential`, testes e docs, incluindo os ADRs 0008 e 0011 | nada | em revisão |
| 2 | Oferta no backend (D6, D7, D10): tabelas `trade_offers` e `trade_offer_lines`, teto público em `MinimumMarginPolicy`, cálculo da primeira oferta no Domain, peça compartilhada com a prospecção, ADR 0016 | 0 | a fazer |
| 3 | `POST /api/trades` com filtro, moeda com fallback, primeira oferta e resposta `created: false` (D2, D3, D4, D11, D13); `routes/api.php`; `VerifySecret` por consumidor | 1, 2 | a fazer |
| 4 | Leituras (rotas 2 e 3) com o código da entrega + ADR 0015 (D5) | 3 | a fazer |
| 5 | Renegociação (rota 4, D12 com os pontos R4.x fechados) e mensagem enviada (rota 5) | 4 | a fazer |
| 6 | Aba Trades mostra a oferta enviada e o histórico de rodadas | 2 | a fazer |

O PR 3 destrava o webhook do price-cd: é ele que devolve o `uuid` que o price-cd repassa ao chatbot.

## Critérios de aceite

- Criar uma Trade pelo price-cd devolve o `uuid` e grava a primeira oferta. Quando nada é ofertável, ou o Supplier está bloqueado, a resposta é distinta e não é erro, e nada é gravado.
- Jogo encalhado ou em giveaway não entra na oferta, mas continua como linha marcada na Trade.
- A Trade criada com `currency: eur` tem a oferta em euro. Sem `currency`, ou sem cotação da TF2 em euro, ela sai em TF2.
- A primeira oferta usa o lucro da categoria: VIP 80%, Normal (sem categoria) 70%, Varejo 60%. Supplier novo cai em Normal.
- O teto de cada linha sai de `MinimumMarginPolicy`, sem cópia das faixas fora dela.
- Cada rodada de renegociação cria uma oferta nova; as anteriores não mudam.
- Um pedido que cabe nos tetos gera uma oferta com exatamente o total pedido; um pedido acima dos tetos gera a oferta final, com todas as linhas no teto.
- Nenhuma oferta passa do teto de qualquer linha. Com a oferta final, um novo pedido responde `409`.
- O `from-price-researcher` continua igual para as listas e para a pesquisa de bundle.
- Os links de entrega já enviados continuam funcionando depois do rename.
- A leitura pelo uuid traz a oferta vigente (jogos, região, valores, total), a moeda, o link e o código, e nenhum campo interno nem oferta anterior.
- A listagem traz só as Trades em negociação, da mais recente para a mais antiga, e nenhum código.
- Sem token, ou com o token de outro consumidor, as rotas respondem `401`.
