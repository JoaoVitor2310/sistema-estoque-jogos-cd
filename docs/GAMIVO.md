# Gamivo — Referência

> **Documentação oficial da API Gamivo:** [`docs/Gamivo_Public_API.html`](Gamivo_Public_API.html) — spec completa (endpoints, schemas, erros).  
> **Tabela oficial de taxas:** [`docs/GAMIVO_Merchant-pricing.pdf`](GAMIVO_Merchant-pricing.pdf) — fonte de verdade para todas as fórmulas.  
> ⚠️ **`API_KEY_GAMIVO` é produção real.** Nunca chamar endpoint sem autorização explícita. Ver regras em [`docs/agents/security-and-guardrails.md`](agents/security-and-guardrails.md).

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
- **`GET /accounts/sales/history` devolve uma linha por oferta vendida, não por pedido.** Várias linhas podem compartilhar o mesmo `order_id`, cada uma com seu próprio `product_id`, `quantity`, `profit` e `seller_tax`. Ver "Baixa de vendas" nas notas de implementação.
- **`GET /accounts/sales/order-details/{orderId}` — chave do objeto:** é `<offer_id>` (integer como string), não `product_name`. O endpoint devolve as keys do **pedido inteiro**, não da oferta que se estava consultando.
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

O piso vence o teto: quando `min_api > max_api`, o resultado é o `min_api`. É por isso que o preço sem concorrente (abaixo) não precisa de tratamento próprio para o conflito — ele reusa este mesmo clamp.

**Os dois limites são editáveis à mão** na tela de Keys (`PUT /keys/{key}`), sem validação cruzada — `min_api > max_api` é estado legítimo, produzido pelo próprio auto-sell ao travar o teto de uma key velha. A automação não recua diante da edição: o `max_api` editado permanece (nada o regula depois do import), mas o `min_api` editado é sobrescrito na passada das 07:30 do `RegulateMinApiUseCase`, que recalcula o piso de toda key não vendida.

#### Sem concorrente utilizável (vendedor único)

> Implementado em `MinMaxPriceCalculator::soleSellerPrice()`. Constante: `NO_COMPETITOR_MARKET_MULTIPLIER = 1.10`.

O `max_api` é **folga para valorização**, não um preço-alvo: ele só é seguro porque quem freia o preço de verdade é o concorrente contra quem o `ComparisonAlgorithm` mira. Sem concorrente esse freio some e a folga viraria o preço praticado — pedíamos múltiplos do valor real do jogo (custo €1 + mercado €20 dão `max_api` = €160).

Nesse caso a âncora passa a ser o `market_price` da key governante:

```
price = clamp(market_price × 1.10, min_api, min(market_price × 1.10, max_api))
```

| | Com concorrente | Sem concorrente |
|---|---|---|
| Âncora do preço | preço do concorrente | `market_price` da governante |
| Teto aplicado | `max_api` | `min(market_price × 1.10, max_api)` |
| Piso aplicado | `min_api` | `min_api` |

São dois regimes **condicionais**, não uma composição: o `max_api` continua valendo integralmente quando há concorrente, para que o preço acompanhe a valorização real do jogo. O que muda sem concorrente é o **papel** dele — deixa de ser âncora e passa a ser só limite superior, aplicado por cima do teto de mercado. Na prática ele quase nunca binda, porque a fórmula do import o deixa bem acima do mercado; ele aparece quando alguém edita o `max_api` na tela de Keys ou quando o auto-sell o trava numa key velha. Nesses dois casos a coluna precisa significar teto nos dois regimes, senão a tela promete um limite que a precificação ignora.

**O que conta como "sem concorrente".** `ComparisonResult::REASON_SOLE_SELLER` cobre os casos em que a nossa oferta existe mas nenhum concorrente serve de âncora. Não cobre "não temos oferta no produto": isso segue sendo `REASON_NO_COMPETITORS`, com `offerId` zerado, porque não há o que reprecificar.

**Vendedor ignorado conta como concorrente para subir, nunca para descer.** A regra é **direcional** de propósito, e a posição relativa decide:

| Onde está o vendedor de `SELLERS_TO_IGNORE` | O que fazemos | Por quê |
|---|---|---|
| **Acima** de nós (somos os mais baratos) | competimos: miramos no preço dele − `PRICE_STEP` | mirar nele é **subir**, e o `max_api` limita até onde |
| **Abaixo** de nós | `sole_seller` — preço pelo mercado pesquisado | segui-lo seria **descer** até o preço irreal que o pôs na lista de ignorados |

Por isso `handleWeAreLowest` **não** filtra `SELLERS_TO_IGNORE` e `handleWeAreNotLowest` filtra. A assimetria parece descuido e não é — não trocar por simetria. Vale só com `detectDumpers: true` (a reprecificação); o auto-sell chama com `false` e não filtra ninguém, então lá "sem concorrente" é a ausência literal de outra oferta.

**Unidade.** O multiplicador vive em `seller_price` (payout), não em preço de vitrine. Com as taxas vigentes o retail resultante fica em torno de **122%–126%** do mercado, variando com a faixa de preço porque a taxa fixa pesa mais nos jogos baratos.

**Defasagem aceita.** `market_price` é, por definição, o preço pesquisado no dia da trade — é ele que rateia o `individual_cost` do lote e fixa `simulated_income` e `purchase_profit`, então **não deve ser atualizado** (ver [`docs/adr/0004`](adr/0004-recalculate-trade-on-key-edit.md)). Usá-lo como âncora aqui significa precificar por uma referência de compra, não de hoje: uma key parada num jogo que valorizou fica anunciada por um preço velho. O teto de valorização volta a valer assim que surge um concorrente. Preço corrente pediria uma coluna própria — ver `docs/IMPROVEMENTS.md`.

**Não é persistido.** Ausência de concorrência é estado do produto, muda a cada minuto e não é propriedade da key, então não vira coluna: `max_api` continua significando "teto de valorização". Os dois pontos que precisam da decisão (`AutoSellUseCase` e `UpdateOffersUseCase`) já têm as offers em mãos, então não há chamada extra à API para descobri-la — e não existe um `RegulateMaxApiUseCase`. Decisão registrada em [`docs/adr/0010`](adr/0010-sole-seller-ceiling-computed-not-persisted.md).

**Consequência aceita: a tela não explica o preço.** Como o `max_api` fica intacto e o sistema não guarda o preço praticado (a tela de Keys mostra Min. API e Max. API, nunca o valor anunciado), uma oferta a €10 sob um `max_api` de €24 parece errada até você lembrar desta regra. **Isso é o esperado, não um clamp quebrado.** O único registro de qual regime precificou cada oferta é o canal de log `schedulers`, na chave `pricing` (`competitor` ou `sole_seller`). Exibir o estado na interface foi avaliado e adiado — ver `docs/IMPROVEMENTS.md`.

**Volta do concorrente.** Nada precisa ser desfeito: o `ComparisonAlgorithm` deixa de devolver `sole_seller`, o caminho competitivo volta a clampar pelo `max_api` que sempre esteve no banco, e a folga de valorização é recuperada já na primeira passada que enxergar o concorrente.

**Única exceção — key velha.** O passo final do `AutoSellUseCase` trava o `max_api` de keys com ≥ `OLD_KEY_MONTHS` meses no preço de listagem. Se essa listagem aconteceu sem concorrente, o valor gravado é o teto de mercado, e ele **não** é recuperado quando o concorrente volta — a única marca permanente que o regime de vendedor único deixa no banco.

---

## Agendamentos Laravel

Definidos em `routes/console.php`, fuso `America/Sao_Paulo`:

| Expressão CRON | Fuso | Use Case | Finalidade |
|---|---|---|---|
| `* * * * *` | America/Sao_Paulo | `UpdateOffersUseCase` (sem mode) | A cada minuto: sobe o preço onde já somos os mais baratos e desce onde não somos, numa única passada |
| `0 6,18 * * *` | America/Sao_Paulo | `UpdateSoldOffersUseCase::executeFromGamivo` | Dá baixa nas vendas — janela de 30 dias |
| `0 7 * * *` | America/Sao_Paulo | `UpdatePopularityUseCase` | Atualiza popularidade via SteamCharts |
| `0 7 * * *` | America/Sao_Paulo | `AlertExpiringKeysUseCase` | Alerta de keys expirando |
| `0 7 * * *` | America/Sao_Paulo | `AlertDollarVariationUseCase` | Alerta de câmbio |
| `30 7 * * *` | America/Sao_Paulo | `RegulateMinApiUseCase` | Recalcula `min_api` de todas as keys não vendidas (via `MinimumMarginPolicy`) — roda antes do auto-sell |
| `0 6 * * *` | America/Sao_Paulo | `ResolveSteamIdsUseCase` | Busca Steam IDs pendentes |
| `5 * * * *` | UTC | `SyncBundlesFromApiUseCase` | Sincroniza bundles da API GG.deals |
| **Manual** | — | `gamivo:auto-sell` (artisan) | `AutoSellUseCase` — **não roda em cron**, precisa ser disparado manualmente |

> `UpdateOffersUseCase` roda como um único `Schedule::call()` a cada minuto, sem `mode` — processa subida e descida de preço na mesma passada, com `->name()->withoutOverlapping()` (se uma execução ultrapassar 1 min, o(s) tick(s) seguinte(s) são pulados até o mutex liberar, sem empilhar processos concorrentes). O filtro `OffersUpdateMode` (`WeAreLowest`/`WeAreNotLowest`) continua existindo na assinatura de `execute()` para uso manual/pontual, mas o cron não passa mais `mode` — eliminou de vez a possibilidade de dois processos do scheduler concorrendo pela mesma API.

---

## Ferramentas de diagnóstico (manuais, read-only)

Dois comandos artisan para investigar se o `min_api` está deixando dinheiro na mesa (preço mais alto que o mercado aceitaria) ou represando keys sem venda. Nenhum dos dois muta nada — só `GET`, nunca `PUT`/`POST`. Não têm agendamento (`routes/console.php`); rodar manualmente quando quiser reavaliar `MinimumMarginPolicy`.

| Comando | O que simula | Escopo |
|---|---|---|
| `gamivo:min-api-floor-report` | `ComparisonAlgorithm` (mesma chamada do `UpdateOffersUseCase`) sem aplicar o clamp de `min_api`/`max_api`, para medir o preço "natural" de mercado vs. o piso praticado | Ofertas **já ativas** na Gamivo (`GET /offers` + 1x `GET /products/{id}/offers` por produto) |
| `gamivo:unlisted-min-api-report` | `ComparisonAlgorithm` com `detectDumpers: false, requireOurOffer: false` (mesma chamada do `AutoSellUseCase::processGroup`), key a key, para achar quem nunca chega a ser listado hoje | Keys elegíveis para auto-sell **ainda não listadas** (`KeyRepository::findEligibleForAutoSell()` + 1x `GET /products/{id}/offers` por produto) |

Ambos aceitam `--limit=N` (testar num subconjunto antes do run completo) e `--delay-ms` (pausa de cortesia entre chamadas por produto, padrão 150ms) e gravam um JSON detalhado em `storage/app/diagnostics/`.

Os dois separam, no resultado, ofertas com **margem de mercado negativa** (preço atual abaixo do `individual_cost`) das com margem positiva mas abaixo do exigido — só o segundo grupo é sinal de que um tier de `MinimumMarginPolicy` está alto demais; o primeiro é estoque com o preço de mercado desabado, ver [`docs/IMPROVEMENTS.md`](IMPROVEMENTS.md#processo-para-estoque-morto-keys-com-mercado-abaixo-do-custo-de-compra).

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

### Baixa de vendas: uma linha de histórico por oferta

O `UpdateSoldOffersUseCase` reconcilia keys vendidas cruzando dois endpoints com granularidades diferentes:

| Endpoint | Granularidade | O que traz |
|---|---|---|
| `GET /accounts/sales/history` | uma linha por **oferta** vendida | `product_id`, `quantity`, `profit`, `seller_tax` daquela oferta |
| `GET /accounts/sales/order-details/{orderId}` | um objeto por **pedido** | todas as keys entregues, agrupadas por `offer_id` |

Um pedido com três ofertas produz **três linhas** de histórico com o mesmo `order_id` e **um** order-details com as três keys. Por isso o use case agrupa as linhas por `order_id` (elas podem até cair em páginas diferentes da paginação) e chama o order-details **uma vez por pedido**.

**Linha repetida pela paginação:** o histórico é lido em páginas de 25 por offset, e vendas novas
entrando durante a varredura empurram as linhas — a mesma pode ser lida em duas páginas. Como uma
oferta é única por produto, o `groupByOrder` descarta a segunda linha com o mesmo `product_id` no
mesmo pedido; sem isso o bruto do pedido dobra e o excedente é distribuído entre as keys. Só
janelas que terminam em *hoje* correm esse risco: um intervalo fechado no passado não ganha linhas
novas enquanto é paginado. *(Encontrado em 2026-09-01 no pedido `ed3cc6f3`, que chegou com duas
linhas do mesmo jogo e distribuiu €1,79 num pedido de €1,43.)*

**Casamento linha ↔ key:** o histórico expõe `product_id` e o order-details expõe `offer_id` — não dão join direto. O elo é local: `keys.gamivo_id` **é** o `product_id` da Gamivo, então cada linha reivindica, entre as keys entregues, `quantity` keys cujo `gamivo_id` bate com seu `product_id`.

**Casamento parcial:** o casamento é **linha a linha**, não tudo-ou-nada — uma key entregue que não está na base não tira das outras o valor da própria oferta. Só o que sobra sem par é rateado. Da situação mais comum para a mais extrema:

| Situação | O que cada key recebe | Atribuição registrada |
|---|---|---|
| toda linha achou suas keys | o líquido da própria oferta | `matched` |
| sobrou linha **e** sobrou key | as linhas que casaram mantêm o valor exato; o bruto das linhas órfãs é dividido por igual entre as keys órfãs | `partially_matched` |
| sobrou key, mas nenhuma linha | as keys órfãs ficam **sem baixa** — não gravar é retentável na passada seguinte, gravar chute é definitivo | `partially_matched` |
| sobrou linha, mas nenhuma key para recebê-la | o bruto do pedido inteiro dividido por igual entre todas as keys — errado por key, mas preserva o total recebido | `equal_split` |
| nada casou | idem acima | `equal_split` |
| pedido sem detalhes / sem key de texto | nenhuma key é tocada | `no_order_details` / `no_text_keys` |

Os casos são o enum `App\Domain\Enums\OrderPayoutAttribution`. Todo pedido que não seja `matched` gera um `Log::warning` no canal `schedulers` (o mesmo do resumo do run) dizendo qual pedido e o que sobrou, e o resumo traz o campo `orders_by_attribution` com a contagem por caso — sem ele um run cheio de rateio igual pareceria saudável.

> **Por que não pular o pedido inteiro no rateio igual:** um `sold_price` gravado é definitivo, porque `execute()` nunca sobrescreve key já vendida. O rateio igual é mantido por decisão de produto (registrar a receita vale mais que a precisão por key); a melhoria de observabilidade em cima dele está em [`docs/IMPROVEMENTS.md`](IMPROVEMENTS.md).

**`seller_tax` entra no payout, não sai dele.** O bruto de uma linha é `profit + seller_tax` (`UpdateSoldOffersUseCase::grossOf`). O `seller_tax` é o VAT que o comprador pagou por cima — na linha, `gross_price = net_price + seller_tax`, e o `profit` é calculado sobre o `net_price`, já sem imposto. Como a operação é brasileira e não recolhe VAT na UE, a Gamivo **repassa** esse valor ao vendedor; somá-lo é o comportamento correto, e não uma inflação do valor gravado. A alíquota acompanha o país do comprador (`tax_rate`, ex.: `23% PT`, `23% SK`), então o payout da mesma oferta varia conforme quem compra. *(Confirmado com o dono da conta em 2026-08-27, contra dois pedidos reais: `ab6c09d8` — `profit` 0,30/0,13 com `seller_tax` 0,13/0,09 — e `5ae0af1c` — `profit` 1,90/1,15/0,63 com `seller_tax` 0,46/0,30/0,19, cujo payout de €4,62 confere com `Σ(profit + seller_tax) − 0,01`.)*

**Taxa de mediação:** `IncomeCalculator::MEDIATION_FEE` (€0,01) é cobrada **uma vez por pedido**, não por linha — descontá-la em cada linha multiplicaria a taxa pelo número de ofertas. O desconto e o arredondamento acontecem no `OrderPayoutSplitter`, que usa o método do maior resto para que a soma dos `sold_price` gravados seja exatamente o líquido do pedido (dividir €1,00 entre 3 keys grava 0,34 / 0,33 / 0,33, nunca 0,99).

> Antes dessa correção, cada linha do histórico era tratada como o pedido inteiro: o use case buscava as N keys do pedido para **cada** uma das N linhas e dividia o profit *daquela linha* por N. A idempotência do `execute()` fazia **uma linha qualquer** vencer — as outras N−1 eram descartadas como "já vendidas", e qual delas sobrava dependia da ordem em que a paginação por data devolvia as linhas, não do pedido. O valor gravado era, na prática, sorteado: um pedido de €4,62 em três volumes de Nekopara ficou como 0,48 em cada key (a linha de 1,45 dividida por 3), e um de €0,64 em duas keys ficou como 0,11 em cada (a linha de 0,13 + 0,09 dividida por 2).

### Backfill: corrigir venda já gravada errada

`php artisan gamivo:backfill-sold-prices` é o único caminho para consertar um `sold_price`
errado, porque o cron não o alcança: `execute()` nunca sobrescreve key já vendida.

Ele **não** procura o erro por heurística. Chama o mesmo `processOrder` do cron
(`UpdateSoldOffersUseCase::reconcileFromGamivo`, que recalcula sem gravar), compara com o que
está no banco e só age onde diverge — o que torna impossível estragar uma venda correta. No
banco, aliás, não dá para distinguir as duas coisas: `sold_at` é `date`, sem hora, e não existe
coluna de `order_id`, então duas vendas do mesmo dia pelo mesmo valor podem ser um pedido
rateado errado ou dois pedidos independentes. Só a API sabe.

| Opção | Padrão | Para quê |
|---|---|---|
| `--days` | 30 | janela em dias contados de hoje |
| `--since` / `--until` | — / hoje | janela por data; `--since=2024-01-01` varre a base inteira |
| `--order` | — | corrige só estes pedidos, consultados um a um; repetível |
| `--except` | — | deixa de fora um pedido ajustado à mão; repetível |
| `--except-key` | — | deixa de fora uma key só, corrigindo as irmãs do pedido; repetível |
| `--tolerance` | 0,01 | diferença ignorada como ruído do maior resto |
| `--apply` | desligado | sem ela nada é gravado; com ela ainda pede confirmação |

**Corrigir uma lista conhecida:** com `--order`, o comando usa o filtro `order` da API e consulta
cada pedido isoladamente — dois requests por pedido, em vez de paginar a janela inteira. É o que
permite descobrir *quais* pedidos corrigir num ambiente (varredura completa, minutos) e aplicar em
outro em segundos, sem perder nenhuma verificação: o valor continua sendo recalculado e comparado
contra o banco daquele ambiente, e a trilha é gravada igual. `storage/app/order_list.php` monta a
linha de comando a partir das trilhas de dry-run.

**Trilha de auditoria:** toda passada — inclusive o dry-run — grava um JSON em
`storage/app/diagnostics/backfill-sold-prices-<data>-{dry-run,applied}.json` com o **antes e o
depois** de cada key (`sold_at`, `sold_price`, `sale_profit`, `sale_profit_percent`), o motivo da
correção (`rateio por linha` ou `venda sem baixa`), a atribuição do pedido, e também o que foi
deliberadamente **preservado**. É o que permite conferir depois que nada foi alterado além do
previsto — sem ele, uma correção em massa é irreversível e invisível.

**Varrer a base inteira:** `--since=2024-01-01` cobre desde a primeira venda. Cada pedido custa uma
chamada de order-details, então uma janela de anos são milhares de requisições e vários minutos —
por isso há barra de progresso. A varredura completa é feita **por trimestre**, não numa passada
única: cada fatia gera sua própria trilha, e um erro no meio não invalida o resto.

```bash
bash storage/app/backfill_quarters.sh            # dry-run das 11 fatias
bash storage/app/backfill_quarters.sh --apply    # grava fatia a fatia

docker compose exec -T app-cd php artisan tinker \
  --execute="require 'storage/app/audit_summary.php';"   # consolida as trilhas
```

Os dois scripts vivem em `storage/app/` (fora do git, como as demais ferramentas de bancada). O
primeiro roda as fatias em sequência e concentra a saída em
`~/backfill-logs/quarters-<data>.log` (o diretório do host, porque `storage/app/` pertence ao
`www-data` e o script roda como o usuário); o segundo lê todos os JSONs de trilha e imprime uma
linha por passada mais o total do que foi efetivamente gravado.

**O que ele se recusa a fazer:**

- **Pedido sem base para corrigir.** Atribuição `equal_split`, `no_order_details` ou
  `no_text_keys` é listada e **pulada** — trocar um valor errado por um chutado não é conserto.
  Só `matched` e `partially_matched` viram escrita.
- **Reembolso lançado à mão.** Duas assinaturas, ambas preservadas e listadas à parte (ver
  "Venda reembolsada" em [`CONTEXT.md`](../CONTEXT.md)):

  | `sold_price` | Significa | Rótulo no relatório |
  |---|---|---|
  | = `individual_cost` − 1 **e** `sale_profit` = −1 | fornecedor devolveu a key, não a taxa | `só a taxa de €1 perdida` |
  | = `individual_cost` **e** `sale_profit` = 0 | fornecedor devolveu key e taxa | `zerada contra o custo` |
  | ≤ 0 | fornecedor não devolveu nada; prejuízo da key e da taxa | `prejuízo lançado à mão` |

  As três cobrem os desfechos de [`docs/PRODUCT.md`](PRODUCT.md#como-o-reembolso-é-registrado-no-sistema).
  A regra da taxa exige as duas condições juntas — prejuízo de exatamente €1 **e** custo recuperado
  por inteiro — porque só o prejuízo de €1 pode ser venda real no vermelho. *(Na base há 38 keys
  com essa assinatura, e nas 38 o `sold_price` é o custo menos €1 exato.)*
  A regra do custo exige o lucro **exatamente** zero e **não vale** quando o valor se repete entre
  keys do mesmo pedido: o rateio errado gravava o mesmo número em todas as keys, e quando esse
  número calhava de ser o custo de uma delas o lucro dava zero por acaso. Valor repetido entre
  irmãs é digital do bug; reembolso é lançado key a key. *(Caso real: no pedido `ab6c09d8` a
  SteamWorld Build tem custo 0,11 e o rateio gravou 0,11 nas duas keys.)* Ajuste manual que não
  siga nenhum dos três padrões precisa de `--except=<order_id>` (pedido inteiro) ou
  `--except-key=<key_code>` (uma key só, deixando as irmãs serem corrigidas).

`sold_price` nulo é o caso oposto: é venda que passou da janela de 30 dias do cron sem baixa
nenhuma, aparece como `— sem baixa` no relatório e deve mesmo ser gravada.

A gravação inteira roda numa transação.

Ao gravar, recalcula também `sale_profit`/`sale_profit_percent` e corrige o `sold_at` para a
data da linha — a correção do rateio muda o lucro da key, e deixar o lucro velho seria pior que
o valor velho.

### Auto-sell: agrupamento por `gamivo_id` (venda FIFO)

Uma oferta Gamivo é **uma por produto**: um único `seller_price` e um pool de keys, vendidas **FIFO na ordem em que foram enviadas** (a primeira key do upload é a primeira vendida).

Por isso o `AutoSellUseCase` **agrupa as keys elegíveis por `gamivo_id`** e processa cada grupo como **uma oferta + um `uploadKeys` em lote** — nunca repetindo o ciclo `createOffer→updateOffer→uploadKeys→changeOfferStatus` por key na mesma oferta (era essa repetição que gerava o `400 "Wait for the current action"`).

A lógica tem **duas etapas, nessa ordem** — a distinção é fundamental:

1. **Quais keys listar (decisão por key).** Cada key do grupo é avaliada **individualmente**: entra se o mercado cobre o `min_api` **dela**, ou se não há concorrente utilizável. Uma key reprovada é pulada sozinha — **não bloqueia as outras** do mesmo produto. Exemplo: se a key de menor `id` tem `min_api` acima do mercado, ela é pulada, mas uma key mais nova cujo `min_api` o mercado cobre **é listada normalmente**. A **idade não é reavaliada aqui**: o `min_api` já embute a idade, pois a `MinimumMarginPolicy` o rebaixa ao `FLOOR` para keys com ≥ `OLD_KEY_MONTHS` meses (persistido pelo `RegulateMinApiUseCase`, que roda antes do auto-sell).
2. **Qual preço praticar (a governante).** Só **entre as keys aprovadas** na etapa 1, a mais antiga (**menor `id`** — governante) define o `seller_price` único da oferta, pois é a primeira a ser vendida (FIFO). Como uma key velha já tem `min_api` no `FLOOR`, o preço dela naturalmente pode ser baixo — sem nenhuma lógica de "override" no auto-sell.

Demais regras:

- **Escopo = keys elegíveis (não listadas).** O grupo contém apenas keys ainda **não listadas** (`findEligibleForAutoSell` já filtra `listed_at IS NULL`). Se o produto já tem keys listadas de rodadas anteriores, elas **não entram no grupo** — a governante é a mais antiga **entre as elegíveis aprovadas**, não a mais antiga absoluta do produto. O `seller_price` é recalculado por ela e sobrescreve o da oferta; o `UpdateOffersUseCase` reajusta em seguida usando a governante **da oferta já listada** (ver abaixo). *(Decisão de negócio confirmada — 2026-07-20.)*
- **Upload em ordem de `id` ASC** (`findEligibleForAutoSell` já retorna `orderBy('id')`), espelhando a ordem de venda da Gamivo.
- **`max_api`** é travado no preço praticado apenas nas keys **individualmente** velhas (≥ `OLD_KEY_MONTHS`) — o único ponto do auto-sell que ainda avalia a idade diretamente, já que a `MinimumMarginPolicy` cobre só o `min_api`, não o `max_api`.
- **Sem concorrente, o preço de entrada sai do `market_price` da governante**, não do `max_api` (ver "Sem concorrente utilizável" acima). Esse ramo **nunca recusa a listagem**: sem concorrência não há motivo competitivo para ficar de fora, e o pior caso é entrar no próprio `min_api`.
- **Confirmação parcial:** após o upload, verifica na oferta quais códigos apareceram e marca `listed_at` **só nos confirmados**; os não confirmados seguem elegíveis na próxima rodada. Isso vale inclusive quando a própria governante não confirma — as keys mais novas confirmadas são listadas e a governante tenta de novo depois (a eventual inversão de ordem FIFO é aceita por ser rara). *(Decisão de negócio confirmada — 2026-07-20.)*

### Reprecificação: a mesma governante, agora por `listed_at`

O `UpdateOffersUseCase` reajusta o preço de ofertas **já listadas**, minutos ou dias depois do auto-sell — nesse ponto, todas as keys do grupo já têm `listed_at` preenchido, então a governante deixa de ser "menor `id`" (só fazia sentido pré-listagem, quando `listed_at` ainda não existia) e passa a ser **`listed_at` ASC, com `id` ASC como desempate** (`KeyRepository::findGoverningKeyByGamivoId`). O desempate é necessário porque `listed_at` é uma coluna `date` (sem hora): toda vez que o `AutoSellUseCase` confirma um lote inteiro na mesma rodada, essas keys empatam na mesma data, e a de menor `id` foi a primeira enviada no `uploadKeys` daquele lote.

O clamp de `min_api`/`max_api` usa **só** os limites da governante — não mais um `MIN(min_api)`/`MAX(max_api)` agregado do grupo. Isso fecha a lacuna que existia antes: uma governante velha (com `max_api` travado no preço de listagem, ver acima) podia ter o preço reajustado para cima porque uma key mais nova do mesmo grupo tinha `max_api` mais alto — o agregado furava a trava de idade. Se a governante tiver `min_api`/`max_api` nulo (não deveria acontecer, já que o `RegulateMinApiUseCase` mantém isso preenchido diariamente), o clamp cai para `MinMaxPriceCalculator::FLOOR`/`CEILING`, mesmo fallback que o `AutoSellUseCase` já usa.

A governante **não** é obtida consultando a Gamivo (ex: ordem de retorno de `GET /offers/{id}/keys/active`) — decisão registrada em [`docs/adr/0006`](adr/0006-governing-key-order-from-local-data.md): a API não documenta garantia de ordenação, e a chamada extra por produto a cada ciclo (hoje, a cada minuto) não compensaria o ganho.

### Testar sem chamar a API real

```php
Http::fake([
    '*/api/public/v1/products/*/offers' => Http::response([...]),
    '*/api/public/v1/offers/*'          => Http::response(12345),
]);
```
