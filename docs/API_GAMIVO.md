# API_GAMIVO.md — API própria que se comunica com Marketplace Gamivo


## Visão Geral
API própria que contém lógica de verificação de melhor preço de venda, comparando os preços de concorrentes e validando se não estão muito abaixo. Essa API não é a API oficial deles, foi construída pensando em utilizar a API oficial com lógicas de negócio, o link da API oficial está a seguir: 

https://www.gamivo.com/api-documentation/public 


Suas responsabilidades principais são:

1. **Precificar automaticamente** as ofertas ativas na Gamivo, batendo o preço dos concorrentes.
2. **Listar automaticamente chaves a venda** quando forem lucrativas (ou obrigatórias por tempo).
3. **Dar baixa no estoque** dos jogos já vendidos na Gamivo.
4. **Atualizar popularidade** dos jogos via scraping do SteamCharts.
5. **Pesquisar o melhor preço** de um jogo individualmente sob demanda.

Esses fluxos são disparados por **CRON jobs internos** (via `node-cron`) ou por **chamadas HTTP** de sistemas externos.

Futuramente a ideia é colocar essa API no Sistema Estoque diretamente, o problema é que tem muita lógica, ainda será decidido, opiniões são bem vindas.


## Agendamentos (CRON) — fuso `America/Sao_Paulo`

Declarados em `src/app.ts`. Todos disparam via `axios.get` para o próprio serviço (`THIS_URL`).

| Expressão | Endpoint disparado | Finalidade |
|---|---|---|
| `5 * * * *` | `GET /api/update-offers` | A cada hora, nos 5min, recalcula e atualiza preços das ofertas ativas na Gamivo. |
| `0 7 * * *` | `GET /api/update-sold-offers` | Diariamente às 7h, dá baixa dos jogos vendidos no Sistema Estoque. |
| `0 8 * * *` | `GET /api/when-to-sell` | Diariamente às 8h, avalia quais chaves já podem/devem ser listadas a venda. |
| `0 7 * * *` | `GET /api/update-popularity` | Diariamente às 7h, atualiza popularidade dos jogos via SteamCharts. |

> Mesmo com os CRONs internos, os endpoints continuam **públicos** e podem ser chamados por sistemas externos (ex.: para disparos manuais ou reprocessamentos).

---

## Sistemas externos envolvidos

- **Gamivo API** (`${URL}/api/public/v1/...`) — origem das ofertas, concorrentes, histórico de vendas; destino das atualizações de preço/criação de ofertas.
- **Sistema Estoque** (`${URL_SISTEMA_ESTOQUE}`) — origem das chaves disponíveis, destino das baixas de venda e atualizações de popularidade.
- **SteamCharts** (scraping) — fonte da popularidade dos jogos.

---

## Endpoints

Todas as rotas são montadas sob o prefixo **`/api`**.

### `GET /api/update-offers`

**Finalidade:** reprecificar **todas as ofertas ativas** do vendedor na Gamivo.

**Regra de negócio:**

1. Busca os `productId` de todas as ofertas com `status == 1` (ativas) via `GET ${URL}/api/public/v1/offers`.
2. Para cada `productId`, chama `compareById(id, consideraSamfit=true)`:
   - Obtém todas as ofertas concorrentes (`GET /products/{id}/offers`), ordenadas por `retail_price` ASC.
   - Identifica os **"candangos"** (vendedores com `completed_orders < 4000`).
     - Se houver **≥ 3 candangos**, o menor preço total prevalece. Caso contrário, desconsidera candangos.
     - **Nota atual:** o código força `menorPreco = menorPrecoTotal` (considera TODOS, pois "também somos candangos").
   - Detecta **"samfiteiros"** (vendedores com preço muito abaixo do segundo colocado):
     - Se o segundo colocado > 1, o samfiteiro é quem tem preço ≥ 10% abaixo.
     - Se ≤ 1, a diferença tem que ser ≥ 5%.
     - Se houver samfiteiro e **nós não formos o 2º lugar**, o preço usado vira o do 2º colocado (ignora o samfiteiro).
     - Se **nós formos o 2º**, não altera (retorna `-4`).
   - Se **já somos o menor** e a diferença para o 2º é **< 0,04€**, não altera (retorna `-4`).
   - Se já somos o menor e a diferença ≥ 0,04€, sobe nosso preço para `2ºMenor - 0,014`.
   - Caso genérico: o preço final listado é `menorPreco - 0,014`, convertido para **preço sem taxa** (via `priceWithoutFee`), pois a Gamivo adiciona taxas na listagem.
   - **Verificações contra API de concorrentes** (`checkOthersAPI`): se algum concorrente específico já tem o melhor preço (lógica no helper), nem mexe.
3. **Limitadores por produto** (do Sistema Estoque via `searchByIdGamivo`):
   - `minApi` ← menor `minApiGamivo` entre os registros desse `idGamivo`.
   - `maxApi` ← maior `maxApiGamivo`.
   - Limite mínimo efetivo: `max(minApi, 0.02)`.
   - Limite máximo efetivo: `min(maxApi, 500)`.
   - O preço calculado é **clampado** nesse intervalo.
4. Chama `editOffer(dataToEdit)` que faz `PUT /api/public/v1/offers/{offerId}` com `seller_price` (e, se `wholesale_mode` 1/2, também os tiers — calculados via `wholesaleWithoutFee(menorPrecoParaWholesale)`).
5. **Ignora** os productIds `1767` (Random Game) e `42931` (Spotify Premium).

**Códigos de retorno internos de `compareById`:**
- `-1`: produto não encontrado / erro 404/403 na API Gamivo.
- `-2`: produto sem concorrentes.
- `-4`: já temos o melhor preço (ou não vale mudar).
- `-5`: sem ofertas na Gamivo.

**Resposta HTTP:**
- `200`: `{ message, updatedGames: number[] }` — IDs que tiveram preço editado com sucesso.
- `500`: erro inesperado.

---

### `GET /api/update-sold-offers`

**Finalidade:** varrer o **histórico de vendas do último mês na Gamivo** e registrar as baixas no Sistema Estoque.

**Regra de negócio:**

1. Chama `GET /api/public/v1/accounts/sales/history/{offset}/25?filters={dateFrom: hoje-1mês, dateTo: hoje, statuses:[COMPLETED]}`, paginando de 25 em 25 até zerar.
2. Para cada venda:
   - Calcula `profit = offer.profit + offer.seller_tax - 0,01` (ajuste fino do lucro bruto retornado pela Gamivo).
   - Busca os detalhes do pedido via `GET /accounts/sales/order-details/{order_id}` para extrair a(s) **chave(s)** entregue(s).
   - Casa o `parent` (nome do produto) em `orderData.keys` com `offer.product_name` para coletar apenas as chaves desse produto naquele pedido.
   - Acumula `{ product_name, profit, saleDate, keys }`.
3. Se o pedido conteve múltiplas chaves, divide o `profit` igualmente entre elas.
4. Envia o payload para o Sistema Estoque via `POST ${URL_SISTEMA_ESTOQUE}/venda-chave-troca/update-sold-offers` — a baixa definitiva é responsabilidade do estoque.

**Resposta HTTP:**
- `200`: `{ message, response }`.
- `400`: histórico não encontrado.
- `500`: erro inesperado.

---

### `GET /api/update-popularity`

**Finalidade:** manter atualizada a popularidade Steam de cada jogo cadastrado.

**Regra de negócio:**

1. Busca a lista de jogos no Sistema Estoque: `GET ${URL_SISTEMA_ESTOQUE}/games/search-popularity`. Cada item traz `id_steamcharts`.
2. Para cada jogo, faz scraping de `https://steamcharts.com/app/{id_steamcharts}` com cheerio e extrai o **segundo** `span.num` (pico de jogadores nas últimas 24h).
3. Atribui `game.popularity` (0 se não encontrar).
4. Envia o array atualizado via `POST ${URL_SISTEMA_ESTOQUE}/games/update-popularity`.
5. Se o envio falhar, envia e-mail de alerta (`sendEmail2`).

**Resposta HTTP:**
- `200`: `{ message, response }`.

---

### `GET /api/when-to-sell`

**Finalidade:** decidir **quais chaves já podem ser listadas a venda** — seja por preço atingido, seja por tempo decorrido — e automatizar a listagem das que passaram do limite de tempo.

**Regra de negócio:**

1. Busca as chaves candidatas em `GET ${URL_SISTEMA_ESTOQUE}/venda-chave-troca/when-to-sell` (chaves ainda não listadas).
2. Para cada chave:
   - Pega o melhor preço atual via `searchBestPrice(idGamivo, consideraSamfit=false)` (lógica idêntica à de `compareById`, porém estruturada por `status`: `JA_TEM_MELHOR_PRECO` / `ATUALIZAR_PRECO` / `SEM_CONCORRENTES` / `PRECO_INDETERMINADO`).
   - Se `PRECO_INDETERMINADO`, aplica `minMaxApi(games, bestPrice)` — clampa entre o mín/máx permitido pela API do Sistema Estoque para aquele jogo (ou seja, geralmente assume o máximo).
   - Converte para **preço com taxa** via `priceWithFee`.
   - Anota `maisDe7Meses` e `maisDe10Meses` com base em `dataAdquirida`.
   - Classificação:
     - **Por preço:** `precoComTaxa > minimoParaVenda` e **< 10 meses** → entra em `gamesToSellByPrice`.
     - **Por tempo:** entre 7 e 10 meses → entra em `gamesToSellByTime` (marcação `maisDe7Meses=true`).
     - **Auto-listagem (> 10 meses):** cria oferta automaticamente e faz baixa na venda:
       1. `createOffer(game, bestPrice)` (`POST /offers`). Se a Gamivo responder com reason contendo um `[offerId]` já existente (jogo já cadastrado como oferta inativa), reativa via `changeStatus(offerId, 1)`.
       2. `insertOfferKey(offerId, { keys: [chaveRecebida] })` — **até 5 tentativas** com 1s entre falhas.
       3. `editOffer(dataToEdit)` para calibrar preço — também **até 5 tentativas**.
       4. `POST ${URL_SISTEMA_ESTOQUE}/venda-chave-troca/insert-data-venda` com `{chaveRecebida, updateMinApiGamivo: true}` — marca a chave como posta a venda e atualiza o `minApiGamivo`.
3. Envia e-mails de resumo:
   - **"When to Sell PREÇO"**: jogos por preço.
   - **"When to Sell TEMPO"**: jogos por tempo (7-10 meses).
   - **"When to Sell AUTOMÁTICO"**: jogos listados automaticamente (>10 meses).
   - **"When to Sell ERRO"**: falhas (offer, key ou data de venda).

**Resposta HTTP:**
- `200`: `{ message, gamesToSellByPrice, gamesToSellByTime, gamesToSellAutomatically }`.
- `500`: erro inesperado.

---

### `GET /api/auto-sell`

**Finalidade:** listar automaticamente à venda as chaves cujo **lucro potencial já atinge o percentual mínimo** esperado (mesmo antes de 10 meses).

**Regra de negócio:**

1. Busca candidatas em `GET ${URL_SISTEMA_ESTOQUE}/venda-chave-troca/auto-sell`.
   - **Atenção:** no código atual esse fetch está comentado e a lista é inicializada vazia — mantido para execução manual/testes.
2. **Deduplica por chave** via `filterKeyMostRecentBundle` — se a mesma key aparece em múltiplos bundles, mantém a do `bundle_release_date` mais recente.
3. Para cada chave:
   - `searchBestPrice` + fallback via `minMaxApi` (igual ao `when-to-sell`).
   - **Filtro bundle/choice** (`bundleOrChoice`):
     - `choice` → pode vender imediatamente.
     - `bundle` com `bundle_release_date < 4 meses` → **não vende** (espera valorizar).
   - **Verificação de lucro mínimo** (`hasMinimumProfitAutoSell(price, valorPagoIndividual, dataAdquirida)`):
     - `lucro = price - valorPagoIndividual` (e `valorPagoIndividual=0.01` se for ≤ 0).
     - Percentual mínimo escalonado **(regras aplicadas em ordem; a primeira que casar vence)**:
       - `valorPagoIndividual > 15` → **50%**
       - `valorPagoIndividual > 10` → **60%**
       - `valorPagoIndividual < 1` → **75%**
       - `dataAdquirida > 10 meses` → **60%**
       - `dataAdquirida > 7 meses` → **70%**
       - `dataAdquirida > 4 meses` → **80%**
       - `valorPagoIndividual > 20` → **40%** (obs.: essa linha, pela ordem atual, nunca é atingida)
       - Caso contrário → **78%** (default)
     - Precisa ainda ter `lucro > 0,08`.
   - Se passou tudo: mesmo fluxo do `when-to-sell` automático — `createOffer` → `insertOfferKey` (5 tentativas) → `editOffer` (5 tentativas) → `insertDataVendaOnSistemaEstoque(chave, updateMinApiGamivo=false)`.
4. E-mails:
   - **"Auto Sell"**: comentado no código atual.
   - **"Auto Sell ERRO"**: só é enviado em caso de erros.

**Resposta HTTP:**
- `200`: `{ message, gamesToSellAutomatically }`.
- `500`: erro inesperado.

---

### `GET /api/products/priceResearcher/:slug`

**Finalidade:** consulta **on-demand** do melhor preço recomendado para um jogo específico, dado seu slug Gamivo.

**Regra de negócio:**

1. `GET ${URL}/api/public/v1/products/by-slug/{slug}` → obtém `productId`.
2. Executa `bestPriceResearcher(productId)`:
   - Aplica a mesma mecânica de candangos/samfiteiros do `compareById`, **mas sem descontar taxa** (retorna em preço bruto).
   - Se **não somos o menor preço**: retorna `menorPreco - 0,01` (com piso de `0,13`).
   - Se **somos o menor** e o 2º está **≥ 0,10€ acima**: sobe para `2º - 0,01`.
   - Se **somos o menor** e a diferença para o 2º é **< 0,10€**: retorna nosso preço atual.
   - Se somos o único vendedor: retorna nosso `retail_price`.
   - **Não** considera samfiteiros (comentado de propósito).

**Resposta HTTP:**
- `200`: `{ message, menorPreco }`.
- `500`: erro na pesquisa.

---

### `GET /api/bump-topics`

**Finalidade:** dar bump em tópicos fixos no **SteamTrades** via scraping/AJAX autenticado por cookie.

**Regra de negócio:**

Tópicos bumpados (hard-coded em `bumpSteamTradesTopics`):

| Código | Descrição |
|---|---|
| `533Bx` | HAVE GAMES, WANT TF2 |
| `HVWGM` | HAVE TF2, WANT POPULAR GAMES |
| `LgwHr` | CHOICE TOPIC |

Envia `POST https://www.steamtrades.com/ajax.php` com `do=trade_bump`, `code=<id>`, `xsrf_token=<token>` e `PHPSESSID` no Cookie.

**Resposta HTTP:**
- `200`: `{ message, result }`.
- `400`: nenhum tópico foi bumpado.
- `500`: erro inesperado.

> **Atenção:** `xsrf_token`, `PHPSESSID` e os códigos de tópicos estão hard-coded. Cookies expiram — precisa ser rotacionado manualmente. O CRON para este endpoint está comentado em `app.ts`.

---

## Conceitos de negócio recorrentes

### Candango(será descontinuado)
Vendedor com menos de `4000` `completed_orders`. Heurística para identificar quem ainda não é "estabelecido" e costuma praticar preços artificialmente baixos. **Se houver ≥ 3 candangos** numa oferta, o algoritmo passa a considerá-los (porque viraram a realidade de mercado). No código de precificação das nossas ofertas, hoje **sempre consideramos candangos** (linha `menorPreco = menorPrecoTotal`).

### Samfiteiro
Concorrente com preço muito abaixo do 2º colocado — sinal de anomalia (erro de precificação, estoque defeituoso, preço de queima). A regra:
- Se 2º preço > 1€ → diferença ≥ **10%** caracteriza samfiteiro.
- Se 2º preço ≤ 1€ → diferença ≥ **5%** caracteriza samfiteiro.

Quando detectado, em vez de bater o samfiteiro, o sistema mira no 2º colocado (para não destruir margem).

### `priceWithFee` / `priceWithoutFee`
A Gamivo cobra percentual + fixo, com alíquotas diferentes para preços **< 8€** e **≥ 8€**:
- `priceWithFee(x) = (x + fixo) / (1 - %)` — quanto o cliente paga a partir do nosso `seller_price`.
- `priceWithoutFee(x) = x * (1 - %) - fixo` — quanto fica líquido a partir de um preço final. Tem piso de `0,01`.

O **`seller_price` enviado pra Gamivo é sempre "sem taxa"** (líquido) — a plataforma adiciona a taxa depois.

### `wholesale_mode`
Campo da oferta na Gamivo:
- `0`: sem wholesale (só retail).
- `1` / `2`: wholesale ativo em tiers 1 e 2, com preços calculados via `wholesaleWithoutFee(menorPrecoParaWholesale)`.

### `minApiGamivo` / `maxApiGamivo`
Limites **por chave** armazenados no Sistema Estoque. São guard-rails que impedem o bot de listar por preços absurdos (clamp em `update-offers` e em `when-to-sell`/`auto-sell` quando `PRECO_INDETERMINADO`).

### Bundle vs. Choice
- **`bundle`** (ex.: Humble Bundle): precisa esperar valorizar; regra atual = **só vender após 4 meses** do `bundle_release_date`.
- **`choice`**: sem restrição — libera imediatamente.

### Classificação por idade da chave (`when-to-sell`)
- `< 7 meses` → só lista se `precoComTaxa > minimoParaVenda`.
- `7 a 10 meses` → marca como candidata por tempo (e-mail "TEMPO").
- `> 10 meses` → **lista automática** (sem precisar do e-mail).

### Percentual mínimo de lucro (`auto-sell`)
Escalonado por valor pago e idade. Resumo prático:
- Jogos caros (>10€) aceitam margens menores (60%/50%).
- Jogos antigos (>7 meses) também — estamos mais dispostos a girar estoque.
- Jogos baratos (<1€) exigem 75% (o fixo da taxa Gamivo corrói muito a margem nesses preços).
- Default: 78%.

---

## Resumo rápido — como sistemas externos se integram

1. **Disparar um reprocessamento de preços agora:** `GET ${THIS_URL}/api/update-offers`.
2. **Forçar baixa de vendas:** `GET ${THIS_URL}/api/update-sold-offers`.
3. **Avaliar chaves pendentes:** `GET ${THIS_URL}/api/when-to-sell`.
4. **Forçar listagem automática por lucro:** `GET ${THIS_URL}/api/auto-sell`.
5. **Atualizar popularidades:** `GET ${THIS_URL}/api/update-popularity`.
6. **Bump de tópicos SteamTrades:** `GET ${THIS_URL}/api/bump-topics`.
7. **Consultar preço ideal de um jogo:** `GET ${THIS_URL}/api/products/priceResearcher/{slug}`.

Todos os endpoints são **GET**, sem body, sem autenticação interna (proteção é a nível de rede). O Sistema Estoque é a **fonte da verdade** para chaves e metadados; a Gamivo é a fonte da verdade para concorrência e histórico de vendas.
