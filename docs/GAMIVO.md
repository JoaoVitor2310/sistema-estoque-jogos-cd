# Gamivo — Referência

> **Documentação oficial da API Gamivo:** [`docs/Gamivo_Public_API.html`](Gamivo_Public_API.html) — spec completa (endpoints, schemas, erros).  
> **Tabela oficial de taxas:** [`docs/GAMIVO_Merchant-pricing.pdf`](GAMIVO_Merchant-pricing.pdf) — fonte de verdade para todas as fórmulas.  
> ⚠️ **`API_KEY_GAMIVO` é produção real.** Nunca chamar endpoint sem autorização explícita. Ver regras em `CLAUDE.md`.

---

## Conceitos de Negócio — Regras de Bundle (leia antes do código)

Existem **duas janelas de tempo** diferentes para bundles. São independentes e não devem ser confundidas:

### Janela 1 — Exclusão de venda durante o bundle (21 dias)
Quando um bundle é lançado, ele fica disponível para compra por ~21 dias. Durante esse período, o preço da key despenca porque qualquer um pode comprá-la barata no bundle. **Não faz sentido listar a key à venda nesse momento.**

→ O `AutoSellUseCase` **exclui** keys de jogos em bundles lançados há menos de 21 dias.  
→ Constante: `KeyEligibility::BUNDLE_EXCLUSION_DAYS = 21`

### Janela 2 — Maturação pós-bundle (4 meses / 120 dias)
Após o bundle sair de circulação, a key começa a valorizar gradualmente porque o supply diminui. Em geral, após ~4 meses de um bundle, o preço já recuperou e pode estar acima do custo de aquisição.

→ Constante: `KeyEligibility::BUNDLE_MATURATION_DAYS = 120`

Resumo visual:
```
Dia 0          Dia 21              Dia 120+
|── bundle ────|── key no estoque ─|── valorizada → listar ──▶
   (não vende)   (não vende ainda)    (auto-sell candidata)
```

---

## Integração com a API Gamivo

**Base URL:** `https://backend.gamivo.com/api/public/v1/...`  
**Autenticação:** `Authorization: Bearer <TOKEN>` em todos os requests.  
**Versão da API:** `0.0.1`  
**Implementação Laravel:** `app/Services/External/GamivoApiService.php`

### Códigos de erro de autenticação (HTTP 401)

| `codeMessage` | Significado |
|---|---|
| `UNAUTHORIZED` | Sem token |
| `UNAUTHORIZED_INVALID_TOKEN` | Token inválido ou malformado |
| `UNAUTHORIZED_EXPIRED_TOKEN` | Token expirado — sistema envia e-mail de alerta automaticamente |
| `UNAUTHORIZED_INVALID_SCOPE` | Token sem o scope necessário |

### Notas importantes sobre a API

- **Token Gamivo expira.** O sistema detecta `UNAUTHORIZED_EXPIRED_TOKEN` e envia e-mail de alerta. Atualizar `API_KEY_GAMIVO` manualmente no `.env`.
- **Formato `created_at` do histórico de vendas** é não padrão: `"2025-04-13UTC17:44:480"`. Para obter só a data: `explode('UTC', $date)[0]`.
- **`POST /offers` + oferta já existente:** Gamivo retorna `"Offer already exists [12345]"`. Extrair o ID com regex `/\[(\d+)\]/` e reativar via `PUT /offers/{offerId}/change-status`.
- **Delay de 500ms entre criar oferta e fazer upload de key:** necessário — a Gamivo precisa de tempo para registrar a oferta antes de aceitar chaves.
- **Upload de keys com até 5 tentativas e 1s de delay:** race condition real na API — sempre implementar retry.
- **`400 "Wait for the current action to end. Progress: X/Y"`:** a Gamivo processa **uma ação por oferta de cada vez** (upload de key, mudança de status). Qualquer mutação na mesma oferta enquanto a anterior não terminou retorna esse 400. Ele é **transitório, não é falha** — aguardar e reenviar resolve. `GamivoApiService::sendWithActionLockRetry()` reaplica esse retry (`ACTION_LOCK_RETRIES` × `ACTION_LOCK_RETRY_DELAY_S`s) em **todos** os endpoints de mutação (`createOffer`, `updateOffer`, `changeOfferStatus`, `uploadKeys`). Atenção especial: reativar a oferta (`change-status`) logo após `uploadKeys` colide com o job de upload ainda em andamento — daí o `Progress: 1/1`. `isKeyListed` confirmar a key **não** garante que o job já terminou no lado da Gamivo.
- **`GET /accounts/sales/order-details/{orderId}` — chave do objeto:** é `<offer_id>` (integer como string), não `product_name`. Verificar ao usar.
- **Scraping SteamCharts:** frágil. O **segundo** `span.num` é o pico 24h. Se o HTML mudar, para de funcionar.

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

---

### Fórmulas de Taxa

**`priceWithFee(sellerPrice)`** — converte preço sem taxa → preço que o cliente vê:
```
if sellerPrice < 8:
    feePercentage = 0.06 ; feeFixed = 0.25
else:
    feePercentage = 0.08 ; feeFixed = 0.40

priceWithFee = (sellerPrice + feeFixed) / (1 - feePercentage)
```

**`priceWithoutFee(clientPrice)`** — converte preço final → seller_price (o que enviar à Gamivo):
```
basePrice = clientPrice × (1 - feePercentage) - feeFixed
if basePrice < 0: basePrice = 0.01
return round(basePrice, 2)
```

> **Nota:** o threshold para a taxa é €8, não €4. Variáveis de ambiente do sistema legado tinham "4" no nome — isso era um equívoco histórico.

---

### Algoritmo de Comparação de Preços

> Implementação completa: `app/Domain/Pricing/ComparisonAlgorithm.php`  
> Testes: `tests/Unit/Domain/Pricing/ComparisonAlgorithmTest.php`

---

### Conceitos de Precificação

#### Price Dumper
Concorrente com preço anomalamente baixo — muito abaixo do 2º colocado.

**Critério:**
- Se 2º preço > €1 → diferença ≥ **10%** do 2º = price dumper.
- Se 2º preço ≤ €1 → diferença ≥ **5%** do 2º = price dumper.

**Ação:** mira no 2º colocado (protege margem).

**Nota:** em `AutoSellUseCase`, a detecção de price dumpers é **desativada** (`detectDumpers: false`) para não bloquear listagens legítimas.

#### Wholesale Mode
- `0` → só varejo (retail).
- `1` / `2` → wholesale ativo (tiers 1 e 2).

Ao editar oferta com wholesale:
```
tier_one_seller_price = retail_price_com_taxa / 1.035
tier_two_seller_price = retail_price_com_taxa / 1.035
```

#### Clamp min/max

> Implementado em `MinMaxPriceCalculator::clamp()`. Constantes: `FLOOR = 0.02`, `CEILING = 500.0`.

```
price = max(min_api, price)
price = min(max_api, price)
```

---

## Agendamentos Laravel

Definidos em `routes/console.php`, fuso `America/Sao_Paulo`:

| Expressão CRON | Fuso | Use Case | Finalidade |
|---|---|---|---|
| `*/5 * * * *` | America/Sao_Paulo | `UpdateOffersUseCase(WeAreLowest)` | A cada 5min: sobe o preço das ofertas onde já somos os mais baratos |
| `5 * * * *` | America/Sao_Paulo | `UpdateOffersUseCase(WeAreNotLowest)` | De hora em hora: tenta recuperar posição nas ofertas onde não somos os mais baratos |
| `0 6,18 * * *` | America/Sao_Paulo | `UpdateSoldOffersUseCase::executeFromGamivo` | Dá baixa nas vendas — janela de 2 dias |
| `0 7 * * *` | America/Sao_Paulo | `UpdatePopularityUseCase` | Atualiza popularidade via SteamCharts |
| `0 7 * * *` | America/Sao_Paulo | `KeyService::checkExpiringKeys` | Alerta de keys expirando |
| `0 7 * * *` | America/Sao_Paulo | `AssetService::checkDollarAlert` | Alerta de câmbio |
| `30 7 * * *` | America/Sao_Paulo | `RegulateMinApiUseCase` | Recalcula `min_api` de todas as keys não vendidas (via `MinimumMarginPolicy`) — roda antes do auto-sell |
| `0 6 * * *` | America/Sao_Paulo | `GameService::searchGamesIdSteam` | Busca Steam IDs pendentes |
| `5 * * * *` | UTC | `SyncBundlesFromApiUseCase` | Sincroniza bundles da API GG.deals |
| **Manual** | — | `gamivo:auto-sell` (artisan) | `AutoSellUseCase` — **não roda em cron**, precisa ser disparado manualmente |

> ⚠️ **`UpdateOffersUseCase(WeAreLowest)` roda nos minutos `0,5,10,15...` e `UpdateOffersUseCase(WeAreNotLowest)` roda no minuto `5` de cada hora — colidem todo minuto `:05`.** Nenhum dos dois usa `->withoutOverlapping()`. Ver `docs/IMPROVEMENTS.md` ("UpdateOffersUseCase — sobreposição de execuções agendadas").

---

## Notas de Implementação

### Formato de datas Gamivo

```php
// created_at vem como "2025-04-13UTC17:44:480" — extrair só a data:
$date = explode('UTC', $sale['created_at'])[0]; // "2025-04-13"
```

### Retry em upload de keys

```php
// POST /offers/{offerId}/keys/upload tem race condition — tentar até 5x com 1s de delay
for ($attempt = 1; $attempt <= 5; $attempt++) {
    $jobId = $this->gamivoApi->uploadKeys($offerId, [$keyCode]);
    if ($jobId) break;
    if ($attempt < 5) sleep(1);
}
```

> Além disso, todos os endpoints **mutadores** (`createOffer`, `updateOffer`, `changeOfferStatus`, `uploadKeys`) reprocessam automaticamente o `400 "Wait for the current action to end"` via `GamivoApiService::sendWithActionLockRetry()` — a Gamivo só processa uma ação por oferta de cada vez.

### Auto-sell: agrupamento por `gamivo_id` (venda FIFO)

Uma oferta Gamivo é **uma por produto**: um único `seller_price` e um pool de keys, vendidas **FIFO na ordem em que foram enviadas** (a primeira key do upload é a primeira vendida).

Por isso o `AutoSellUseCase` **agrupa as keys elegíveis por `gamivo_id`** e processa cada grupo como **uma oferta + um `uploadKeys` em lote** — nunca repetindo o ciclo `createOffer→updateOffer→uploadKeys→changeOfferStatus` por key na mesma oferta (era essa repetição que gerava o `400 "Wait for the current action"`).

A lógica tem **duas etapas, nessa ordem** — a distinção é fundamental:

1. **Quais keys listar (decisão por key).** Cada key do grupo é avaliada **individualmente**: entra se o mercado cobre o `min_api` **dela**, ou se não há concorrentes. Uma key reprovada é pulada sozinha — **não bloqueia as outras** do mesmo produto. Exemplo: se a key de menor `id` tem `min_api` acima do mercado, ela é pulada, mas uma key mais nova cujo `min_api` o mercado cobre **é listada normalmente**. A **idade não é reavaliada aqui**: o `min_api` já embute a idade, pois a `MinimumMarginPolicy` o rebaixa ao `FLOOR` para keys com ≥ `OLD_KEY_MONTHS` meses (persistido pelo `RegulateMinApiUseCase`, que roda antes do auto-sell).
2. **Qual preço praticar (a governante).** Só **entre as keys aprovadas** na etapa 1, a mais antiga (**menor `id`** — governante) define o `seller_price` único da oferta, pois é a primeira a ser vendida (FIFO). Como uma key velha já tem `min_api` no `FLOOR`, o preço dela naturalmente pode ser baixo — sem nenhuma lógica de "override" no auto-sell.

Demais regras:

- **Escopo = keys elegíveis (não listadas).** O grupo contém apenas keys ainda **não listadas** (`findEligibleForAutoSell` já filtra `listed_at IS NULL`). Se o produto já tem keys listadas de rodadas anteriores, elas **não entram no grupo** — a governante é a mais antiga **entre as elegíveis aprovadas**, não a mais antiga absoluta do produto. O `seller_price` é recalculado por ela e sobrescreve o da oferta; o `UpdateOffersUseCase` reajusta em seguida considerando todas as keys. *(Decisão de negócio confirmada — 2026-07-20.)*
- **Upload em ordem de `id` ASC** (`findEligibleForAutoSell` já retorna `orderBy('id')`), espelhando a ordem de venda da Gamivo.
- **`max_api`** é travado no preço praticado apenas nas keys **individualmente** velhas (≥ `OLD_KEY_MONTHS`) — o único ponto do auto-sell que ainda avalia a idade diretamente, já que a `MinimumMarginPolicy` cobre só o `min_api`, não o `max_api`.
- **Confirmação parcial:** após o upload, verifica na oferta quais códigos apareceram e marca `listed_at` **só nos confirmados**; os não confirmados seguem elegíveis na próxima rodada. Isso vale inclusive quando a própria governante não confirma — as keys mais novas confirmadas são listadas e a governante tenta de novo depois (a eventual inversão de ordem FIFO é aceita por ser rara). *(Decisão de negócio confirmada — 2026-07-20.)*

> ⚠️  **Dívida relacionada:** `KeyRepository::findMinMaxByGamivoId` (usado pelo `UpdateOffersUseCase`) usa uma política de `MIN/MAX` diferente da governante FIFO — ver `docs/IMPROVEMENTS.md`.

### Testar sem chamar a API real

```php
Http::fake([
    '*/api/public/v1/products/*/offers' => Http::response([...]),
    '*/api/public/v1/offers/*'          => Http::response(12345),
]);
```
