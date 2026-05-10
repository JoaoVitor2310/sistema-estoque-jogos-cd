# Gamivo — Referência e Migração

> Este documento cobre dois assuntos inseparáveis:
> 1. **Referência do sistema legado** (`gamivo-carca-deals`, Node.js/TypeScript) — algoritmos, contratos de API, fluxos e gotchas.
> 2. **Plano de migração para Laravel** — fases, decisões de arquitetura e checklists.
>
> **Documentação oficial da API Gamivo:** [`docs/Gamivo_Public_API.html`](Gamivo_Public_API.html) — spec completa (endpoints, schemas, erros).  
> **Tabela oficial de taxas:** [`docs/GAMIVO_Merchant-pricing.pdf`](GAMIVO_Merchant-pricing.pdf) — fonte de verdade para todas as fórmulas.  
> ⚠️ **`API_KEY_GAMIVO` é produção real.** Nunca chamar endpoint sem autorização explícita. Ver regras em `CLAUDE.md`.

---

## Conceitos de Negócio — Regras de Bundle (leia antes do código)

Existem **duas janelas de tempo** diferentes para bundles. São independentes e não devem ser confundidas:

### Janela 1 — Exclusão de venda durante o bundle (21 dias)
Quando um bundle é lançado, ele fica disponível para compra por ~21 dias. Durante esse período, o preço da key despenca porque qualquer um pode comprá-la barata no bundle. **Não faz sentido listar a key à venda nesse momento.**

→ O `auto-sell` (`AutoSellUseCase` no Laravel) **exclui** keys de jogos em bundles lançados há menos de 21 dias.  
→ Constante: `KeyEligibility::BUNDLE_EXCLUSION_DAYS = 21`

### Janela 2 — Maturação pós-bundle (4 meses / 120 dias)
Após o bundle sair de circulação, a key começa a valorizar gradualmente porque o supply diminui. Em geral, após ~4 meses de um bundle, o preço já recuperou e pode estar acima do custo de aquisição.

→ O `when-to-sell` (`WhenToSellUseCase` no Laravel — futuro) **aguarda** esse período antes de recomendar listagem.  
→ Constante: `KeyEligibility::BUNDLE_MATURATION_DAYS = 120`

Resumo visual:
```
Dia 0          Dia 21              Dia 120+
|── bundle ────|── key no estoque ─|── valorizada → listar ──▶
   (não vende)   (não vende ainda)    (when-to-sell recomenda)
```

---

## Visão Geral do Sistema Legado

A `gamivo-carca-deals` é uma **API Node.js/Express** que serve como bridge entre a marketplace **Gamivo** e o **Sistema de Estoque** (Laravel). Suas responsabilidades são:

1. **Reprecificar automaticamente** ofertas ativas na Gamivo, competindo com concorrentes.
2. **Listar chaves a venda** automaticamente quando lucrativas ou obrigatórias por tempo.
3. **Dar baixa no estoque** dos jogos vendidos na Gamivo.
4. **Atualizar popularidade** dos jogos via scraping do SteamCharts.
5. **Pesquisar o melhor preço** de um jogo individualmente sob demanda.

Esses fluxos são disparados por **CRONs internos** (node-cron) ou por **chamadas HTTP diretas**.

---

## Stack Atual (Node.js)

- **Node.js + TypeScript (ES Modules)**
- **Express 4** — rotas HTTP
- **node-cron** — agendamentos
- **axios** — HTTP client
- **cheerio** — scraping HTML (SteamCharts)
- **nodemailer** — notificações por e-mail (Gmail SMTP)
- **p-limit** — controle de concorrência (só usado em código comentado)

Porta padrão: `3001`  
Entrypoint: `src/server.ts` → `src/app.ts`

---

## Variáveis de Ambiente (Legado Node.js)

```env
# Servidor
PORT=3001
THIS_URL=http://localhost:3001   # URL própria — usada pelos CRONs para chamar os próprios endpoints

# Gamivo
URL=https://backend.gamivo.com   # Base URL da API Gamivo
TOKEN=<JWT_BEARER_GAMIVO>        # Bearer token da Gamivo (expira — precisa rotacionar)

# Taxa Gamivo — preços >= €8
TAXA_GAMIVO_PORCENTAGEM_MAIORIGUAL_4=0.08   # 8%
TAXA_GAMIVO_FIXO_MAIORIGUAL_4=0.40          # €0,40

# Taxa Gamivo — preços < €8
TAXA_GAMIVO_PORCENTAGEM_MENOR_QUE4=0.06     # 6%
TAXA_GAMIVO_FIXO_MENOR_QUE4=0.25            # €0,25

# Taxa Gift Card
TAXA_GAMIVO_PORCENTAGEM_GIFT_CARD=0.05      # 5%
TAXA_GAMIVO_FIXO_GIFT_CARD=0.10             # €0,10 ← diverge do PDF (€0,20 PSN/Xbox, €0,40 outros)

# Taxa Wholesale
TAXA_WHOLESALE=1.035                         # Divisor wholesale (3,5%)

# Identificação
SELLERS_NAME=CarcaDeals                      # Nome do vendedor na Gamivo

# Sistema Estoque (Laravel)
URL_SISTEMA_ESTOQUE=http://31.97.251.251:170  # Produção
URL_SISTEMA_ESTOQUE_DEV=http://localhost:8000  # Dev
EXTERNAL_SECRET=<BEARER_TOKEN_SISTEMA_ESTOQUE>

# E-mail (Gmail App Password — não a senha normal)
EMAIL_PASS=<APP_PASSWORD_GMAIL>
```

**Nota sobre `TOKEN` Gamivo:** quando o token expira, o sistema detecta `UNAUTHORIZED_EXPIRED_TOKEN` na resposta e envia e-mail de alerta. O token precisa ser atualizado manualmente no `.env`.

---

## Agendamentos CRON — fuso `America/Sao_Paulo`

Declarados em `src/app.ts`. Cada um dispara via `axios.get(THIS_URL + endpoint)`.

| Expressão CRON | Endpoint | Finalidade |
|---|---|---|
| `5 * * * *` | `GET /api/update-offers` | Toda hora (no minuto 5): reprecifica todas as ofertas ativas |
| `0 7 * * *` | `GET /api/update-sold-offers` | Diariamente às 7h: dá baixa das vendas no estoque |
| `0 7 * * *` | `GET /api/update-popularity` | Diariamente às 7h: atualiza popularidade via SteamCharts |
| `0 8 * * *` | `GET /api/when-to-sell` | Diariamente às 8h: avalia e lista chaves automaticamente |
| ~~`*/5 * * * *`~~ | ~~`GET /api/bump-topics`~~ | **Comentado** — bump SteamTrades |

Os endpoints são públicos e também podem ser chamados manualmente a qualquer hora.

---

## Integração com a API Gamivo

**Base URL:** `https://backend.gamivo.com/api/public/v1/...`  
**Autenticação:** `Authorization: Bearer <TOKEN>` em todos os requests.  
**Versão da API:** `0.0.1`

### Códigos de erro de autenticação (HTTP 401)

| `codeMessage` | Significado |
|---|---|
| `UNAUTHORIZED` | Sem token |
| `UNAUTHORIZED_INVALID_TOKEN` | Token inválido ou malformado |
| `UNAUTHORIZED_EXPIRED_TOKEN` | Token expirado — disparar alerta de e-mail |
| `UNAUTHORIZED_INVALID_SCOPE` | Token sem o scope necessário |

### Endpoints Gamivo Utilizados

#### `GET /offers?offset=X&limit=100`
Lista as próprias ofertas. Paginado (máx 100 por request). Retorna array com:
```json
{
  "product_id": 12345,
  "status": 1
}
```
- `status == 1` → oferta ativa; `status == 0` → inativa.
- Usado em `productService.productIds()` para coletar todos os `product_id` ativos.

#### `GET /products/{productId}/offers`
Retorna todas as ofertas de todos os vendedores para um produto. Não é paginado. Array de:
```json
{
  "id": 99887,
  "product_id": 12345,
  "product_name": "Control Ultimate Edition EN Global",
  "seller_name": "CarcaDeals",
  "completed_orders": 5200,
  "rating": 4.9,
  "retail_price": 3.50,
  "wholesale_price_tier_one": 3.10,
  "wholesale_price_tier_two": 3.00,
  "stock_available": 1,
  "invoicable": false,
  "status": 1,
  "wholesale_mode": 0,
  "is_preorder": false
}
```
- Campos mais usados: `seller_name`, `retail_price`, `completed_orders`, `id` (=offerId), `wholesale_mode`, `wholesale_price_tier_one`, `wholesale_price_tier_two`.
- O array vem desordenado — o código sempre ordena por `retail_price ASC`.

#### `GET /products/by-slug/{slug}`
Retorna os dados do produto pelo slug. Campo relevante: `id` (= productId).

#### `PUT /offers/{offerId}`
Atualiza preço de uma oferta.

**Body para `wholesale_mode = 0` (sem wholesale):**
```json
{
  "wholesale_mode": 0,
  "seller_price": 2.99
}
```

**Body para `wholesale_mode = 1` ou `2` (com wholesale):**
```json
{
  "wholesale_mode": 1,
  "seller_price": 2.99,
  "tier_one_seller_price": 2.75,
  "tier_two_seller_price": 2.75
}
```
- `seller_price` é sempre **sem taxa** (a Gamivo adiciona as taxas ao exibir).
- `seller_price` **deve ser maior** que `tier_one_seller_price` e `tier_two_seller_price`.
- Resposta de sucesso: retorna o próprio `offerId` (número).
- Erro possível: `{ reason: "Wait for the current action to end. Progress: " }` — basta ignorar/tentar novamente.

#### `POST /offers`
Cria uma nova oferta.

**Body:**
```json
{
  "product": 12345,
  "wholesale_mode": 0,
  "seller_price": 2.99,
  "tier_one_seller_price": 0,
  "tier_two_seller_price": 0,
  "status": 1,
  "keys": 1,
  "is_preorder": false
}
```
- Se a oferta já existe (inativa), a Gamivo retorna erro com `reason` contendo `[offerId]` no texto (ex: `"Offer already exists [12345]"`). Nesse caso, extrair o ID via regex e reativar via `PUT /offers/{offerId}/change-status`.
- Resposta de sucesso: retorna o `offerId` criado.

#### `POST /offers/{offerId}/keys/upload`
Insere chaves em uma oferta. **Operação assíncrona** — retorna um job ID (integer), não confirma upload imediatamente.

**Body:**
```json
{
  "keys": ["XXXXX-YYYYY-ZZZZZ"]
}
```
- Limite: **10.000 chaves** por request.
- Retorna HTTP 202 com o job ID (ex: `9001`).
- Verificar resultado via `GET /offers/{offerId}/jobs/{jobId}/result`:
  - `{ "progress": "0/1000", "status": "created|running|failed" }` → ainda processando
  - `"Done"` ou `application/zip` → concluído

#### `PUT /offers/{offerId}/change-status`
Ativa ou desativa uma oferta.

**Body:**
```json
{ "status": 1 }
```
- `1` = ativa, `0` = inativa.

#### `GET /accounts/sales/history/{offset}/25?filters=...`
Histórico de vendas paginado (25 por página).

**Filtros (query string como JSON):**
```json
{
  "dateFrom": "2025-03-01",
  "dateTo": "2025-04-01",
  "statuses": ["COMPLETED"]
}
```

**Resposta:**
```json
{
  "count": 150,
  "data": [
    {
      "product_id": 12345,
      "product_name": "Control Ultimate Edition EN Global",
      "order_id": "uuid-da-venda",
      "rating": "-",
      "quantity": 1,
      "net_price": 2.99,
      "gross_price": 3.25,
      "tax_rate": "23% PL",
      "total": 3.25,
      "commission": -0.51,
      "retail_adverb_bid": 0.0,
      "profit": 2.74,
      "seller_tax": 0.0,
      "created_at": "2025-04-13UTC17:44:480",
      "type": "retail",
      "order_status": "COMPLETED"
    }
  ]
}
```
- `created_at` tem formato não padrão: `"2025-04-13UTC17:44:480"` — para obter só a data: `split('UTC')[0]`.
- O lucro real usado é: `profit + seller_tax - 0.01`.
- Paginação: `offset = 0, 25, 50, ...` até `data.length === 0`.

#### `GET /accounts/sales/order-details/{orderId}`
Detalhes de um pedido específico, incluindo as chaves entregues.

**Resposta (conforme documentação oficial):**
```json
{
  "id": "000005a9-b734-11eb-aac1-06b81c19a111",
  "total": 3.50,
  "status": "COMPLETED",
  "external_id": null,
  "keys": {
    "<offer_id>": {
      "keys": [
        {
          "type": "TEXT",
          "key": "XXXXX-YYYYY-ZZZZZ",
          "extension": null
        }
      ],
      "rating": "-"
    }
  }
}
```
- **ATENÇÃO — Inconsistência no código Node.js:** o código usa `keys[offer.product_name]` para acessar as chaves, mas a documentação oficial usa `<offer_id>` (integer como string) como chave do objeto. Verificar comportamento real ao migrar.
- `type` pode ser `TEXT`, `IMAGE` (base64) ou `ERROR`.
- Se a venda teve múltiplas chaves, dividir o `profit` igualmente entre elas.

#### `GET /products/{productId}/offer-id` *(não usado no Node.js — útil na migração)*
Retorna **sua própria oferta** para um produto específico. Elimina a necessidade de listar todas as ofertas e filtrar pelo `seller_name`.

> **Usar na migração:** em vez de buscar todas as ofertas e procurar pelo nome `CarcaDeals`, chamar este endpoint diretamente para obter `offerId`, `wholesale_mode` e preços da oferta atual.

#### `GET /products/no-best-price-offers` *(não usado no Node.js)*
Retorna produtos em que você **não** tem o menor preço.

> **Atenção:** este endpoint **não substitui** a iteração de todos os `product_id` ativos. O algoritmo de reprecificação também precisa verificar produtos em que **já somos o menor preço** — quando o 2º colocado sobe mais de €0,04, o preço deve subir junto. Usar como *complemento* para priorizar a fila, nunca como filtro.

#### `GET /offers/{offerId}/jobs/{jobId}/result`
Verifica o resultado de uma operação assíncrona (upload/delete de chaves).

**Respostas:**
- `{ "progress": "0/1000", "status": "created|running|failed" }` → em andamento
- `"Done"` → concluído com sucesso
- `application/zip` → concluído, arquivo disponível

#### `GET /offers/calculate-seller-price/{offerId}` *(não usado no Node.js)*
Converte preço final do cliente → `seller_price`. Pode substituir `priceWithoutFee()` local.

---

## Algoritmos de Precificação

### Tabela Oficial de Taxas Gamivo

> Fonte: [`docs/GAMIVO_Merchant-pricing.pdf`](GAMIVO_Merchant-pricing.pdf)

#### Retail Sales

| Categoria | Condição | % sobre preço | Taxa fixa |
|---|---|:---:|:---:|
| **Comissão geral** (game keys — categoria padrão) | preço ≥ €8 | 8% | €0,40 |
| **Low value products** | preço < €8 | 6% | €0,25 |
| PlayStation Network e Plus Cards | — | 5% | €0,20 |
| Xbox Subscriptions, Cards e Gift Cards | — | 5% | €0,20 |
| Steam GC, Spotify, Nintendo eShop, Google Play, etc. | — | 3% | €0,40 |
| Software (Antivirus, Cloud, Office, Windows…) | — | 40% | €0,40 |
| Reembolso ao comprador | por pedido | 0% | €1,00 |
| Reembolso de produto revogado | por pedido | 0% | €10,00 |

#### Wholesale

| Categoria | % sobre preço | Taxa fixa |
|---|:---:|:---:|
| Todos os produtos | 3,5% | €0,00 |

→ Divisor usado no código: `1.035`

> **Nota sobre Gift Cards no código Node.js:** `TAXA_GAMIVO_FIXO_GIFT_CARD=0.10` não corresponde a nenhuma linha do PDF (€0,20 PSN/Xbox, €0,40 outros). O sistema atual só negocia game keys, então essa taxa nunca é aplicada na prática.

---

### Fórmulas de Taxa

**`priceWithFee(sellerPrice)`** — converte preço sem taxa → preço que o cliente vê:
```
if sellerPrice < 8:
    feePercentage = 0.06
    feeFixed      = 0.25
else:
    feePercentage = 0.08
    feeFixed      = 0.40

priceWithFee = (sellerPrice + feeFixed) / (1 - feePercentage)
```

**`priceWithoutFee(clientPrice)`** — converte preço final → seller_price (o que enviar à Gamivo):
```
basePrice = clientPrice * (1 - feePercentage) - feeFixed
if basePrice < 0: basePrice = 0.01
return round(basePrice, 2)
```

**`wholesaleWithoutFee(menorPrecoComTaxa)`** — preço wholesale sem taxa:
```
return menorPrecoComTaxa / 1.035
```

> **Nota:** o threshold para a taxa é €8, não €4. As variáveis de ambiente têm "4" no nome (legado), mas o código compara `if (lowestPrice < 8)`.

---

### Algoritmo Principal de Comparação

> ✅ **Migrado para Laravel.** Implementação completa em `app/Domain/Pricing/ComparisonAlgorithm.php` com testes em `tests/Unit/Domain/Pricing/ComparisonAlgorithmTest.php`.
>
> O equivalente Node.js são as funções `compareById` / `searchBestPrice` em `comparisonService.ts`. A lógica de "candango" (`completed_orders < 4000`) foi identificada como **dead code** no Node.js (sempre sobrescrita) e **não foi migrada**.

---

### Algoritmo `bestPriceResearcher` (Price Researcher — sem taxa)

Variante para consulta on-demand, **retorna preço bruto (com taxa)**.

```
1. Buscar GET /products/{productId}/offers
2. Ordenar por retail_price ASC
3. Se NÃO somos o menor:
   - Mesma lógica candango (sem filtro de sellers ignorados)
   - NÃO aplica lógica de samfiteiro
   - menorPreco = menorPrecoTotal
   - Se único vendedor → retornar offers[0].retail_price
   - menorPreco = menorPreco - 0.01
   - Se menorPreco < 0.13 → menorPreco = 0.13
   - Retornar round(menorPreco, 2)
4. Se JÁ somos o menor:
   - Se não há 2º → retornar nosso retail_price
   - diferenca = 2ºMenor - nossoPreco
   - Se diferenca >= 0.10 → menorPreco = 2ºMenor - 0.01 (sobe)
   - Se diferenca < 0.10 → retornar nosso preço atual
```

---

### Clamp por Min/Max API (`minMaxApi`)

Aplicado APÓS calcular o preço, antes de qualquer `editOffer` ou listagem:

```
minApi = min(games[].minApiGamivo)
maxApi = max(games[].maxApiGamivo)

limiteMínimo = max(minApi, 0.02)
limiteMaximo = min(maxApi, 500)

if price < limiteMínimo → price = limiteMínimo
if price > limiteMaximo → price = limiteMaximo
```

Em `update-offers`, os campos no Sistema Estoque são `min_api` / `max_api`.  
Em `when-to-sell` / `auto-sell`, são `minApiGamivo` / `maxApiGamivo`.

---

### Lucro Mínimo para Auto-Sell (`hasMinimumProfitAutoSell`)

Escalonado. **As regras são verificadas em ordem — a primeira que casar vence:**

```
valorPagoIndividual = max(valorPagoIndividual, 0.01)
lucro = price - valorPagoIndividual

1. Se valorPagoIndividual > 15  → percentualMinimo = 50%
2. Se valorPagoIndividual > 10  → percentualMinimo = 60%
3. Se valorPagoIndividual < 1   → percentualMinimo = 75%
4. Se dataAdquirida > 10 meses  → percentualMinimo = 60%
5. Se dataAdquirida > 7 meses   → percentualMinimo = 70%
6. Se dataAdquirida > 4 meses   → percentualMinimo = 80%
7. Se valorPagoIndividual > 20  → percentualMinimo = 40%  ← NUNCA ATINGIDO (regra 1 pega antes)
8. Default                       → percentualMinimo = 78%

lucroMinimo = percentualMinimo * valorPagoIndividual
retornar lucro >= lucroMinimo AND lucro > 0.08
```

---

## Conceitos de Negócio (Precificação)

### Price Dumper
Concorrente com preço anomalamente baixo — muito abaixo do 2º colocado.
No código Node.js legado, chamado de "samfiteiro".

**Critério:**
- Se 2º preço > €1 → diferença ≥ **10%** do 2º = price dumper.
- Se 2º preço ≤ €1 → diferença ≥ **5%** do 2º = price dumper.

**Ação:** mira no 2º colocado (protege margem).

**Nota:** em `when-to-sell` e `auto-sell`, proteção contra price dumpers é **desativada** (`consideraSamfit = false` no Node.js / `detectDumpers: false` no Laravel).

### Wholesale Mode
- `0` → só varejo (retail).
- `1` → wholesale tier 1 e tier 2 ativos.
- `2` → igual ao 1 (o código trata igual).

Ao editar oferta com wholesale:
```
tier_one_seller_price = menorPrecoComTaxa / 1.035
tier_two_seller_price = menorPrecoComTaxa / 1.035
```

### minApiGamivo / maxApiGamivo
Guard-rails por produto. Impedem o bot de listar por preços absurdos.

- Em `when-to-sell` automático (>10 meses): `updateMinApiGamivo = true` → atualiza o `minApiGamivo`.
- Em `auto-sell`: `updateMinApiGamivo = false`.

### Bundle vs. Choice
- **`choice`**: jogo de pacote Humble Choice — pode vender **imediatamente**.
- **`bundle`**: jogo de bundle Humble — aguardar **4 meses** do `bundle_release_date` antes de vender.
- `null`: sem restrição.

### Classificação por Idade (when-to-sell)
- `< 7 meses`: lista só se `priceWithFee(bestPrice) > minimoParaVenda`.
- `7-10 meses`: candidata por **tempo** (envia e-mail "TEMPO" — não lista automaticamente).
- `> 10 meses`: **lista automaticamente** sem precisar de aprovação.

---

## Fluxos Completos

### A. `GET /api/update-offers` — Reprecificação

> ✅ **Migrado para Laravel.** Ver `app/UseCases/Marketplaces/Gamivo/UpdateOffersUseCase.php`.
> Scheduler: `cron('5 * * * *')` em `routes/console.php` (atualmente comentado — aguardando validação em produção).

### B. `GET /api/update-sold-offers` — Baixa de Vendas

```
1. Loop com offset = 0, 25, 50, ...:
   - GET /accounts/sales/history/{offset}/25?filters={dateFrom, dateTo, statuses:[COMPLETED]}
   - Se response.data.length === 0 → parar

2. Para cada oferta da página:
   a. profit = offer.profit + offer.seller_tax - 0.01
   b. saleDate = offer.created_at.split('UTC')[0]
   c. GET /accounts/sales/order-details/{order_id} → orderData
   d. Para cada parent em orderData.keys:
      - Se parent != offer.product_name → pular
      - Coletar keys: orderData.keys[parent].keys[].key
      - Adicionar { product_name, profit, saleDate, keys } ao dataToSend

3. Para cada item em dataToSend:
   - Se keys.length > 0: profit = profit / keys.length

4. POST /keys/update-sold-offers com todo o array dataToSend
```

### C. `GET /api/update-popularity` — Popularidade SteamCharts

```
1. Loop: GET /games/search-popularity?page=1,2,3,...
   - Para cada página: { data: { data: [...], last_page: N } }
   - Continuar até page > last_page

2. Para cada game:
   a. GET https://steamcharts.com/app/{game.steam_id}
   b. Extrair todos $('span.num') → array de strings
   c. game.popularity = parseInt(spans[1])  ← SEGUNDO span (pico últimas 24h)
   d. Se erro ou spans.length < 2 → game.popularity = 0

3. POST /games/update-popularity com { games: [...] }
```

### D. `GET /api/when-to-sell` — Avaliação e Listagem Automática

```
1. GET /keys/when-to-sell → lista de candidatos

2. Para cada game:
   a. searchBestPrice(game.idGamivo, consideraSamfit=false) → bestPrice
   b. Se PRECO_INDETERMINADO → minMaxApi(games, bestPrice)
   c. bestPriceWithFee = priceWithFee(bestPrice.price)
   d. game.maisDe7Meses  = isDateOlderThanMonths(dataAdquirida, 7)
   e. game.maisDe10Meses = isDateOlderThanMonths(dataAdquirida, 10)

   f. Classificação:
      - Se (bestPriceWithFee > minimoParaVenda OR maisDe7Meses) AND !maisDe10Meses:
          → gamesToSell.push(game)
      - Se maisDe10Meses → listar automaticamente:
          1. offerId = createOffer(game, bestPrice.price)
          2. delay(500ms)
          3. Loop até 5 tentativas: insertOfferKey(offerId, key)
          4. Loop até 5 tentativas: editOffer(...)
          5. insertDataVendaOnSistemaEstoque(chave, updateMinApiGamivo=true)

3. Enviar e-mails:
   - gamesToSellByPrice → "When to Sell PREÇO"
   - gamesToSellByTime  → "When to Sell TEMPO"
   - gamesToSellAutomatically → "When to Sell AUTOMÁTICO"
   - errors → "When to Sell ERRO"
```

### E. `GET /api/auto-sell` — Auto-Sell por Lucro

```
1. gamesFromAPI = []  ← LISTA VAZIA (getKeysToList() está COMENTADO)
   Só executa com dados hardcoded manualmente no código.

2. filterKeyMostRecentBundle(gamesFromAPI)

3. Para cada game:
   a. searchBestPrice(idGamivo, consideraSamfit=false) → bestPrice
   b. Se PRECO_INDETERMINADO → minMaxApi()
   c. bundleOrChoice(game): 'bundle' E < 4 meses → pular
   d. hasMinimumProfitAutoSell(...) → Se falhar → pular
   e. createOffer → delay(500ms) → insertOfferKey (5x) → editOffer (5x)
      → insertDataVendaOnSistemaEstoque(chave, updateMinApiGamivo=false)
```

### F. `GET /api/products/priceResearcher/:slug` — Consulta de Preço

```
1. GET /products/by-slug/{slug} → productId
2. bestPriceResearcher(productId) → preço bruto (com taxa)
3. Retornar { message, menorPreco }
```

---

## Contrato de API do Sistema Estoque

Endpoints que o Sistema Estoque deve expor. **Todos com:** `Authorization: Bearer ${EXTERNAL_SECRET}`.

### `GET /keys/when-to-sell`
Retorna chaves não listadas, candidatas para avaliação.

**Resposta esperada:**
```json
{
  "data": [
    {
      "nomeJogo": "Control Ultimate Edition",
      "game_region": null,
      "bundle_type": null,
      "bundle_launch_price": null,
      "bundle_release_date": null,
      "game_popularity": null,
      "idGamivo": "67248",
      "chaveRecebida": "XXXXX-YYYYY-ZZZZZ",
      "precoCliente": "",
      "lucroPercentual": "",
      "minimoParaVenda": "7.00",
      "valorPagoIndividual": "5.16",
      "dataAdquirida": "2025-03-19",
      "dataVenda": null,
      "dataVendida": null,
      "dataExpiracao": null,
      "game_release_date": null
    }
  ]
}
```

### `GET /keys/auto-sell`
Retorna chaves candidatas ao auto-sell por lucro. Mesma estrutura do `when-to-sell`.

> **Nota:** no código atual, a chamada a este endpoint está **comentada** — array inicializado vazio.

### `GET /keys/search-by-id-gamivo/{idGamivo}`
Retorna os limites min/max de preço para um produto Gamivo.

**Resposta esperada:**
```json
{
  "data": [
    {
      "min_api": "0.50",
      "max_api": "8.00"
    }
  ]
}
```

> **INCONSISTÊNCIA:** `offerController.ts` usa `game.min_api` / `game.max_api`, enquanto `comparisonService.minMaxApi()` usa `game.minApiGamivo` / `game.maxApiGamivo`. Padronizar para `min_api` / `max_api` no Laravel.

### `POST /keys/insert-data-venda`
Marca uma chave como "posta à venda".

**Body:**
```json
{
  "key_code": "XXXXX-YYYYY-ZZZZZ",
  "updateMinApiGamivo": true
}
```

### `POST /keys/update-sold-offers`
Registra vendas realizadas.

**Body:**
```json
[
  {
    "product_name": "Control Ultimate Edition EN Global",
    "profit": 2.74,
    "saleDate": "2025-04-13",
    "keys": ["XXXXX-YYYYY-ZZZZZ"]
  }
]
```

### `GET /games/search-popularity?page={page}` e `POST /games/update-popularity`
Ver seção de fluxos (Fluxo C).

---

## Tipos de Dados (TypeScript → PHP/Laravel)

### `GameToList`
```typescript
{
  nomeJogo: string,
  game_region: string | null,
  bundle_type: 'bundle' | 'choice' | null,
  bundle_launch_price: string | null,
  bundle_release_date: string | null,
  game_popularity: number | null,
  idGamivo: string,
  chaveRecebida: string,
  precoCliente: string,
  lucroPercentual: string,
  minimoParaVenda: string | null,
  valorPagoIndividual?: string,
  dataAdquirida: string,           // "YYYY-MM-DD"
  dataVenda?: string | null,
  dataVendida?: string | null,
  dataExpiracao?: string | null,
  game_release_date: string | null,
  maisDe7Meses?: boolean,          // calculado em runtime
  maisDe10Meses?: boolean,         // calculado em runtime
}
```

### `GamivoProductOffers`
```typescript
{
  id: number,                       // offerId
  product_id: number,
  product_name: string,
  seller_name: string,
  completed_orders: number,         // candango se < 4000
  rating: number,
  retail_price: number,             // preço com taxa (o que o cliente vê)
  wholesale_price_tier_one: number,
  wholesale_price_tier_two: number,
  stock_available: number,
  invoicable: boolean,
  status: number,
  wholesale_mode: number,           // 0, 1 ou 2
  is_preorder: boolean,
}
```

### `SoldOffer`
```typescript
{
  product_id: number,
  product_name: string,
  order_id: string,                 // UUID
  quantity: number,
  profit: number,
  seller_tax: number,
  created_at: string,               // "YYYY-MM-DDUTCHH:MM:SS0" (formato não padrão)
  type: string,                     // "retail" | "wholesale"
  order_status: string,             // "COMPLETED"
}
```

### `BestPriceResult`
```typescript
{
  productId: number,
  status: 'ATUALIZAR_PRECO' | 'JA_TEM_MELHOR_PRECO' | 'SEM_CONCORRENTES' | 'PRECO_INDETERMINADO',
  price: number,                    // seller_price sem taxa
  offerId?: number,
  wholesale_mode?: number,
  wholesale_price_tier_one?: number,
  wholesale_price_tier_two?: number,
  menorPrecoParaWholesale?: number, // preço com taxa, para calcular wholesale
}
```

---

## Gotchas e Notas de Implementação

1. **Token Gamivo expira.** Detectar `UNAUTHORIZED_EXPIRED_TOKEN`, enviar e-mail, atualizar manualmente no `.env`.

2. **Formato `created_at` do Gamivo** é não padrão: `"2025-04-13UTC17:44:480"`. Para obter a data: `split('UTC')[0]`.

3. **`editOffer` ignora productIds 1767 e 42931** (hardcoded). Ao migrar, replicar ou tornar configurável.

4. **Inconsistência de nomes de campos:** `offerController.ts` usa `game.min_api` / `game.max_api`; `comparisonService.minMaxApi()` usa `game.minApiGamivo` / `game.maxApiGamivo`. Padronizar para `min_api` / `max_api` no Laravel.

5. **`createOffer` + oferta já existente:** Gamivo retorna `"Offer already exists [12345]"`. Extrair ID com regex `/\[(\d+)\]/` e chamar `changeStatus(offerId, 1)`.

6. **Delay de 500ms entre `createOffer` e `uploadKeys`:** necessário — a Gamivo precisa de tempo para registrar a oferta antes de aceitar keys.

7. **`uploadKeys` com até 5 tentativas e 1s de delay:** race condition real na API. Sempre implementar retry.

8. **Scraping SteamCharts:** frágil. Se o HTML mudar, para de funcionar. A popularidade é o **segundo** `span.num` na página (pico 24h). O primeiro é a média mensal.

9. **`auto-sell` está efetivamente desativado:** `getKeysToList()` está comentado e o array começa vazio.

10. **`hasMinimumProfitAutoSell` tem dead code:** a regra `valorPagoIndividual > 20 → 40%` nunca é atingida porque `> 15` a intercepta antes. Replicar o comportamento atual ou corrigir conscientemente.

11. **`order-details` keys object:** a chave do objeto é `<offer_id>` (string), não `product_name`. O código Node.js acessa por `product_name` — bug silencioso a verificar na migração.

---

## Estrutura de Pastas (Node.js)

```
src/
├── app.ts                 # Express + CRONs (5 agendamentos)
├── server.ts              # listen() na porta 3001
├── routes/
│   ├── index.ts
│   ├── updateOffers.ts    # /update-offers, /update-sold-offers, /update-popularity
│   ├── priceResearcher.ts # /products/priceResearcher/:slug, /products/:id
│   ├── whenToSell.ts      # /when-to-sell, /auto-sell
│   └── steamTrades.ts     # /bump-topics (desativado)
├── controllers/
│   ├── offerController.ts
│   ├── priceResearcherController.ts
│   ├── whenToSellController.ts
│   └── steamTradesController.ts   # desativado
├── services/
│   ├── productService.ts      # Integração com Gamivo e Sistema Estoque
│   ├── offerService.ts        # editOffer, createOffer, insertOfferKey, fetchSalesHistory
│   ├── comparisonService.ts   # compareById, searchBestPrice, candango/price-dumper
│   ├── browserService.ts      # scraping SteamCharts
│   └── emailService.ts        # sendEmail2 (Gmail)
├── helpers/
│   ├── priceWithFee.ts
│   ├── priceWithoutFee.ts
│   ├── wholesaleWithoutFee.ts
│   ├── checkOthersAPI.ts
│   └── isDateOlderThanEightMonths.ts  # → isDateOlderThanMonths(date, months)
└── types/
    ├── CompareResult.ts
    ├── BestPriceResult.ts
    ├── GamivoProductOffers.ts
    ├── GameToList.ts
    └── SoldOffer.ts
```

---

## Plano de Migração

### Status das Fases

| Fase | Entrega | Pré-requisito | Status |
|------|---------|--------------|:------:|
| **0** | Infra compartilhada: `GamivoApiService`, scheduler, alerta de token | — | ✅ |
| **1** | `UpdateOffersUseCase` — reprecificação horária | Fase 0 | ✅ |
| **2** | `UpdatePopularityUseCase` + validar `UpdateSoldOffersUseCase` | Fase 0 | ✅ |
| **3** | `AutoSellUseCase` — listagem automática completa | Fases 0–2 | ✅ |
| **4** | `WhenToSellUseCase` — avaliação diária com regra dos 4 meses | Fases 0–3 | ✅ |
| **5** | Desligar `gamivo-carca-deals`; notificações por e-mail | Fases 0–4 | ⬜ |
| **Futura** | `PriceWholesaleUseCase` — wholesale/B2B | Fase 5 | ⬜ |

---

### Fase 1 — UpdateOffersUseCase (Reprecificação Horária) ✅

> Implementado. Arquivos:
> - `app/UseCases/Marketplaces/Gamivo/UpdateOffersUseCase.php`
> - `app/Domain/Pricing/ComparisonAlgorithm.php` + `ComparisonResult.php` + `OfferData.php`
> - Testes: `tests/Unit/Domain/Pricing/ComparisonAlgorithmTest.php`, `tests/Feature/Keys/UpdateOffersUseCaseTest.php`

---

### Fase 2 — UpdatePopularityUseCase + UpdateSoldOffersUseCase ✅

> Implementado. Arquivos:
> - `app/Services/External/SteamChartsService.php`
> - `app/UseCases/Marketplaces/Gamivo/UpdatePopularityUseCase.php`
> - `app/UseCases/Marketplaces/Gamivo/UpdateSoldOffersUseCase.php` (método `executeFromGamivo`)
> - Testes: `tests/Unit/Services/External/SteamChartsServiceTest.php`, `tests/Feature/Keys/UpdatePopularityUseCaseTest.php`, `tests/Feature/Keys/UpdateSoldOffersUseCaseTest.php`

---

### Fase 3 — AutoSellUseCase ✅

> Implementado. Arquivos:
> - `app/UseCases/Marketplaces/Gamivo/AutoSellUseCase.php`
> - Testes: `tests/Feature/Keys/AutoSellTest.php`

---

### Fase 4 — WhenToSellUseCase ✅

> Implementado. Arquivos:
> - `app/UseCases/Marketplaces/Gamivo/WhenToSellUseCase.php`
> - `app/Domain/Keys/KeyEligibility.php` — constante `BUNDLE_MATURATION_DAYS = 120` + método `hasMinimumProfit()`
> - `app/Services/Keys/KeyRepository.php` — método `findEligibleForWhenToSell()`
> - Testes: `tests/Unit/Domain/Keys/KeyEligibilityTest.php` (hasMinimumProfit), `tests/Feature/Keys/WhenToSellTest.php`

**Diferença do AutoSell:**

| Aspecto | AutoSellUseCase | WhenToSellUseCase |
|---------|----------------|-------------------|
| Gatilho | On-demand / manual | Diário (scheduler 8h) |
| Bundle | Exclui < 21 dias | Aguarda >= 120 dias |
| Lucro mínimo | Não verifica | `hasMinimumProfit()` — escalonado por custo e idade |
| Price Dumper | Desativado (`detectDumpers: false`) | Desativado (`detectDumpers: false`) |
| Age override (>= 10 meses) | Não | Ignora piso min_api + atualiza min_api para preço de listagem |

---

### Fase 5 — Shutdown e Notificações

1. Confirmar que todos os CRONs equivalentes estão ativos no scheduler Laravel
2. Desligar o container/processo Node.js
3. Remover `CARCA_API_GAMIVO` do `.env` (variável legada)
4. Notificações a manter: token expirado (✅ Fase 0), resumo de vendas diário (avaliar após Fase 2)

---

### Fase Futura — PriceWholesaleUseCase

> **Status no Node.js:** desativado. Há planos de reativar.

Modalidade de venda em atacado (divisor `1.035`). Implementar após todas as fases anteriores estabilizarem.

---

## Notas de Implementação Transversais

### Clamp min/max

> ✅ Implementado em `MinMaxPriceCalculator::clamp()`. Constantes: `FLOOR = 0.02`, `CEILING = 500.0`.

### Formato de datas Gamivo

```php
$date = explode('UTC', $sale['created_at'])[0]; // "2025-04-13"
```

### Retry em uploads de key

```php
// POST /offers/{offerId}/keys/upload tem race condition — tentar até 5x com 1s de delay
for ($attempt = 1; $attempt <= 5; $attempt++) {
    $jobId = $this->gamivoApi->uploadKeys($offerId, [$keyCode]);
    if ($jobId) break;
    if ($attempt < 5) sleep(1);
}
```

### Scheduler (routes/console.php)

Entradas das fases 1 e 2 já estão em `routes/console.php` (comentadas — ativar após validação em produção). Para a Fase 4, adicionar quando implementado:

```php
// Fase 4 — avaliação de venda
Schedule::call(fn () => app(WhenToSellUseCase::class)->execute())
    ->dailyAt('08:00')->timezone('America/Sao_Paulo');
```

### Testar sem chamar a API real

```php
Http::fake([
    '*/api/public/v1/products/*/offers' => Http::response([...]),
    '*/api/public/v1/offers/*'          => Http::response(12345),
]);
```
