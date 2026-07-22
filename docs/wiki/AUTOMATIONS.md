# Automações

Toda automação do sistema roda sobre a Gamivo. Referência completa em [`docs/GAMIVO.md`](../GAMIVO.md).

Nesta página, **as tabelas vão do caso mais comum para o mais raro** — a primeira linha é o que acontece na maioria das vezes.

## Agendamentos

Ordenado do mais frequente ao mais raro. Fuso `America/Sao_Paulo`, exceto onde indicado. Definidos em `routes/console.php`, só rodam em `production`.

| Cadência | Cron | Job | O que faz |
|---|---|---|---|
| a cada 5 min | `*/5 * * * *` | `UpdateOffersUseCase(WeAreLowest)` | Onde já somos os mais baratos: sobe o preço até logo abaixo do 2º |
| de hora em hora | `5 * * * *` | `UpdateOffersUseCase(WeAreNotLowest)` | Onde não somos os mais baratos: tenta recuperar posição |
| de hora em hora | `5 * * * *` (UTC) | `SyncBundlesFromApiUseCase` | Sincroniza bundles novos da API GG.deals |
| 2×/dia | `0 6,18 * * *` | `UpdateSoldOffersUseCase` | Dá baixa nas keys vendidas (janela de 2 dias) |
| diário 06:00 | `0 6 * * *` | `GameService::searchGamesIdSteam` | Busca Steam IDs ainda não descobertos |
| diário 07:00 | `0 7 * * *` | `UpdatePopularityUseCase` | Atualiza popularidade via scraping do SteamCharts |
| diário 07:00 | `0 7 * * *` | `KeyService::checkExpiringKeys` | E-mail de alerta de keys expirando |
| diário 07:00 | `0 7 * * *` | `AssetService::checkDollarAlert` | E-mail de alerta de variação do câmbio |
| diário 07:30 | `30 7 * * *` | `RegulateMinApiUseCase` | Recalcula o `min_api` de todas as keys não vendidas |
| **manual** | — | `gamivo:auto-sell` (`AutoSellUseCase`) | Lista keys elegíveis na Gamivo — **não tem cron**, só disparo manual |

**Ordem que importa:** o `RegulateMinApiUseCase` (07:30) precisa rodar **antes** do auto-sell, porque o auto-sell só consulta o `min_api` já gravado — nunca recalcula.

> ⚠️ `WeAreLowest` dispara nos minutos `0,5,10,15...` e `WeAreNotLowest` no minuto `5` de cada hora — **os dois colidem todo minuto `:05`**, e nenhum usa `withoutOverlapping()`. Dívida registrada em [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md).

## `min_api` — o piso de preço de cada key

Recalculado para toda key não vendida (listada ou não) todo dia às 07:30, pela `MinimumMarginPolicy`. É a **fonte única** do piso.

Fórmula geral: **`min_api = custo individual × (1 + margem)`**

### 1. Margem base, por faixa de custo (caso mais comum)

Vale para key recém-comprada e ainda não envelhecida. Jogo caro tolera margem menor; jogo muito barato exige margem maior, porque a taxa fixa da Gamivo corrói proporcionalmente mais.

| Custo individual | Margem | Constante |
|---|---|---|
| €1 – €10 | **60%** | `DEFAULT_MARGIN` |
| < €1 | 55% | `LOW_COST_MARGIN` |
| €10 – €15 | 45% | `HIGH_COST_MARGIN` |
| > €15 | 40% | `VERY_HIGH_COST_MARGIN` |

### 2. Decaimento por tempo (substitui a margem base conforme envelhece)

A base de contagem muda conforme a key esteja listada ou não: uma vez listada, o que importa é há quanto tempo está **exposta ao mercado sem vender** — não há quanto tempo foi comprada.

| Tempo | Não listada (desde `acquired_at`) | Listada (desde `listed_at`) |
|---|---|---|
| < 3 meses | margem base (tabela acima) | margem base (tabela acima) |
| ≥ 3 meses | margem base | **40%** |
| ≥ 4 meses | **40%** | **30%** |
| ≥ 6 meses | **15%** | **20%** |

### 3. Pisos absolutos (casos extremos — vencem tudo acima)

Quando qualquer uma destas condições bate, a margem é ignorada e o `min_api` vai direto ao piso de **€0,02** (`MinMaxPriceCalculator::FLOOR`) — a key sai praticamente sem lucro, porque capital parado vale menos que a margem perdida.

| Condição | Constante | Por quê |
|---|---|---|
| comprada há ≥ 8 meses | `KeyEligibility::OLD_KEY_MONTHS` | Estoque muito parado — liquidar e reinvestir |
| listada há ≥ 10 meses | `LIMBO_MONTHS_THRESHOLD` | Muito tempo à venda sem comprador (limbo) |
| expira em ≤ 30 dias | `KeyEligibility::EXPIRY_PRICE_FLOOR_DAYS` | Perde todo o valor na data de expiração |

> **Atenção à ordem de avaliação.** A tabela acima está por raridade (do mais comum ao mais extremo), mas o **código avalia ao contrário**: primeiro checa os três pisos desta seção 3; só se nenhum bater é que aplica o decaimento (seção 2) e a margem base (seção 1). Na prática: **os pisos sempre vencem as margens.**

**Os pisos são permanentes.** Uma key que entrou em "comprada há ≥ 8 meses" nunca recupera margem normal, nem se for listada depois — `acquired_at` não muda e o tempo só anda pra frente. Isso é proposital: evita `min_api > max_api` quando o auto-sell trava o `max_api` de uma key velha no preço de listagem.

## Listagem automática (`AutoSellUseCase`)

Keys do mesmo produto (`gamivo_id`) compartilham **uma única oferta** na Gamivo, então são processadas em grupo — nunca uma a uma. Motivo em [`docs/adr/0002`](../adr/0002-fifo-grouping-by-marketplace-product.md).

### Critérios de elegibilidade (aplicados por key)

Uma key só entra na rodada se passar em **todos**:

| Critério | Reprova quando |
|---|---|
| Tem `gamivo_id` | está vazio — a key não existe no marketplace |
| Ainda não listada | `listed_at` preenchido |
| Ainda não vendida | `sold_at` preenchido |
| Não é gift link | `key_code` contém `http` (é URL de resgate, não código) |
| Fora da janela de bundle | jogo está em bundle lançado há < 21 dias |

### Etapas do processamento (por grupo de `gamivo_id`)

| # | Etapa | Detalhe |
|---|---|---|
| 1 | Consulta o mercado | `ComparisonAlgorithm` com `detectDumpers: false` — uma consulta por produto |
| 2 | Filtra key a key | Entra quem tem o mercado cobrindo o **próprio** `min_api`. Sem concorrente no mercado → entra pelo teto (`max_api`) |
| 3 | Elege a governante | A mais antiga (**menor `id`**) **entre as aprovadas** — ela define o `seller_price` único da oferta |
| 4 | Cria/reativa a oferta | Preço da governante, com clamp entre o `min_api` e o `max_api` dela |
| 5 | Sobe as keys em lote | Um único `uploadKeys`, em ordem de `id` ASC (a Gamivo vende FIFO — a primeira enviada vende primeiro) |
| 6 | Confirma o que subiu | Marca `listed_at` **só nas keys confirmadas** na oferta |
| 7 | Trava keys velhas | Key comprada há ≥ 8 meses tem o `max_api` travado no preço de listagem |

**Falhas não são fatais:** key reprovada no passo 2 é pulada **individualmente** — não bloqueia as outras do mesmo produto. Key aprovada mas não confirmada no passo 6 continua elegível na próxima rodada.

**Por que uma governante?** A Gamivo vende FIFO dentro de uma oferta, e a oferta tem um preço só. Então só faz sentido a primeira key da fila definir esse preço.

## Reprecificação competitiva (`UpdateOffersUseCase`)

Passo de preço: **€0,014** (`ComparisonAlgorithm::PRICE_STEP`) — sempre entramos logo abaixo do alvo para ficar visível como o mais barato.

| Cenário | Novo preço-alvo |
|---|---|
| Somos os mais baratos, existe um 2º colocado | preço do 2º − €0,014 |
| Não somos os mais baratos | menor preço concorrente − €0,014 |
| Somos os mais baratos e não há concorrente | sem ação |
| **Price dumper detectado** — menor preço está anomalamente abaixo do 2º | ignora o dumper, mira no 2º colocado − €0,014 |
| Price dumper detectado **e já somos o 2º** | sem ação — já estamos na melhor posição possível |
| **1º é bot conhecido, somos o 2º**, diferença p/ ele ≤ 10% e o 3º está ≥ 10% acima | preço do 3º − €0,014 (evita guerra de preço com o bot) |

O preço final sempre passa por clamp entre `min_api` e `max_api` antes de ir para a API.

### Detecção de price dumper

| 2º colocado | Diferença que caracteriza dumper |
|---|---|
| > €1 | ≥ 10% do preço do 2º |
| ≤ €1 | ≥ 5% do preço do 2º |

**Vendedores tratados de forma especial:**

| Lista | Vendedores | Efeito |
|---|---|---|
| `SELLERS_TO_IGNORE` | Buy-n-Play, Playtime, Estateium | Removidos da comparação quando `detectDumpers` está ligado |
| `API_COMPETITOR_SELLERS` | Buy-n-Play, Playtime | Rodam bot de precificação — se estamos logo atrás, miramos no 3º |

> A detecção de dumper **só roda no `UpdateOffersUseCase`**. O `AutoSellUseCase` usa `detectDumpers: false`, para não bloquear listagens legítimas na entrada.

**Wholesale:** com `wholesale_mode` 1 ou 2, o preço de tier é `seller_price / 1.035` (taxa de 3,5%, sem componente fixo), recalculado a cada reprecificação para nunca ficar ≥ `seller_price` (exigência da API).

## Janela de bundle

| Desde o lançamento do bundle | Situação do preço | Auto-sell lista? |
|---|---|---|
| 0 – 21 dias | Em queda livre — o bundle está "em cartaz" e qualquer um compra barato | **Não** |
| > 21 dias | Bundle saiu de circulação, supply diminuindo | Sim |
| ~4 meses+ | Tende a já ter recuperado | Sim |

A janela de 21 dias (`KeyEligibility::BUNDLE_EXCLUSION_DAYS`) bloqueia só a **venda** — não impede a compra. Na verdade é o contrário: a queda no lançamento é justamente o que torna o bundle uma boa oportunidade de **compra**. Ver o termo "Bundle" em [`CONTEXT.md`](../../CONTEXT.md).
