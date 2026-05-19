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

| Expressão CRON | Use Case | Finalidade |
|---|---|---|
| `5 * * * *` | `UpdateOffersUseCase` | Reprecifica todas as ofertas ativas (ativo) |
| `0 6,18 * * *` | `UpdateSoldOffersUseCase` | Dá baixa nas vendas — janela de 2 dias |
| `0 7 * * *` | `UpdatePopularityUseCase` | Atualiza popularidade via SteamCharts |
| `0 7 * * *` | `KeyService::checkExpiringKeys` | Alerta de keys expirando |
| `0 7 * * *` | `AssetService::checkDollarAlert` | Alerta de câmbio |
| `30 7 * * *` | `ReduceAgingKeysMinPriceUseCase` | Reduz `min_api` de keys ≥ 7 meses paradas |
| `0 8 * * *` | `SendDailySalesSummaryUseCase` | Resumo de vendas do dia anterior por e-mail |
| `0 6 * * *` | `KeyService::checkLimboKeys` | Ajusta `min_api` de keys em limbo (≥ 12 meses) |
| `0 6 * * *` | `KeyService::reduceExpiringListedKeysPrice` | Reduz `min_api` de keys listadas expirando |
| `0 6 * * *` | `GameService::searchGamesIdSteam` | Busca Steam IDs pendentes |
| `0 6 * * *` | `GameService::updateMinPrices` | Atualiza preços mínimos de jogos |
| `5 * * * *` | `SyncBundlesFromApiUseCase` | Sincroniza bundles da API GG.deals |
| Manual | `gamivo:auto-sell` | Listagem automática — artisan command |

---

## Status da Migração

A migração do sistema legado Node.js (`gamivo-carca-deals`) para Laravel foi concluída. O container Node.js está desligado.

| Fase | Entrega | Status |
|------|---------|:------:|
| **0** | Infra compartilhada: `GamivoApiService`, scheduler, alerta de token | ✅ |
| **1** | `UpdateOffersUseCase` — reprecificação horária | ✅ |
| **2** | `UpdatePopularityUseCase` + `UpdateSoldOffersUseCase` | ✅ |
| **3** | `AutoSellUseCase` — listagem automática + age override ≥ 10 meses | ✅ |
| **4** | `ReduceAgingKeysMinPriceUseCase` — reduz `min_api` de keys ≥ 7 meses | ✅ |
| **5** | Desligar `gamivo-carca-deals`; notificações por e-mail | ✅ |
| **Futura** | `PriceWholesaleUseCase` — wholesale/B2B | ⬜ |

### Fase 5 — Shutdown e Notificações ✅

1. ✅ CRONs equivalentes confirmados ativos no scheduler Laravel (`routes/console.php`)
2. ✅ Container Node.js (`gamivo-carca-deals`) desligado
3. ✅ `CARCA_API_GAMIVO` removido do `.env` — `KeyService::checkLimboKeys()` migrado para usar `GamivoApiService` + `ComparisonAlgorithm`
4. ✅ Notificações: token expirado (Fase 0), resumo de vendas diário (`SendDailySalesSummaryUseCase` — 8h BRT, não envia se sem vendas)

### Fase Futura — PriceWholesaleUseCase

Modalidade de venda em atacado (divisor `1.035`). Implementar após estabilização das fases anteriores.

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

### Testar sem chamar a API real

```php
Http::fake([
    '*/api/public/v1/products/*/offers' => Http::response([...]),
    '*/api/public/v1/offers/*'          => Http::response(12345),
]);
```
