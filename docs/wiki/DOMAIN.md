# Domínio

Para as definições precisas de cada termo (o que é / o que evitar chamar), veja o glossário em [CONTEXT.md](../../CONTEXT.md). Esta página mostra como as entidades se conectam.

## Entidades

| Entidade | Tabela | Papel |
|---|---|---|
| **Key** | `keys` | Unidade central — uma chave comprada e/ou vendida. Tudo (preço, listagem, trade) gira em torno dela. |
| **Supplier** | `suppliers` | Perfil Steam de onde keys são obtidas via troca. Rastreia `has_traded` (já trocou alguma vez) e `is_added` (curado manualmente) de forma independente. |
| **Trade** | `trades` | Um lote de jogos comprado de uma só vez. O canal de compra (`purchase_channel`) diz de quem: um Supplier (caso comum), a loja de um Bundle (compra direta) ou a Gamivo. |
| **Game** | `games` | Catálogo de jogos — nome, `gamivo_id`, popularidade, preço de referência. |
| **Bundle** | `bundles` | Pacote de jogos vendidos juntos (Humble Bundle, Fanatical, Green Man Gaming...). Tipo `bundle` ou `choice`, resolvido pelo título. |
| **Asset** | `assets` | Ativo de troca (ex.: TF2 Key) com preço em EUR/USD/BRL — usado para converter o custo de uma trade. |
| **Fee** | `fees` | Taxas do marketplace (percentual + fixo, por faixa de preço) — usadas em todo cálculo de income/margem. |

## Relacionamentos

Vínculos reais no banco, começando pelos da Key (a entidade central):

| De | Para | Cardinalidade | Como se ligam |
|---|---|---|---|
| Key | Supplier | N : 1 | `keys.supplier_id` → `suppliers.id` (FK, nullable) |
| Key | Trade | N : 1 | `keys.trade_id` → `trades.id` (FK, nullable) — o lote de onde a key veio; populado só no import por trade |
| Key | Game | N : 1 | `keys.gamivo_id` ↔ `games.gamivo_id` — **join por string, sem FK** |
| Trade | Supplier | N : 1 | `trades.supplier_id` → `suppliers.id` (FK, nullable) — só no canal `supplier_trade` |
| Trade | Bundle | N : 1 | `trades.bundle_id` → `bundles.id` (FK, nullable) — só na compra direta (`bundle_store`) |
| Game | Bundle | N : N | pivot `bundle_games` (com `bundle_launch_price`) |

O join `Key ↔ Game` por string é dívida conhecida — não há integridade referencial e `game_name`/`region` ficam duplicados em `keys`. Plano de normalização em [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md).

Entidades usadas apenas em cálculo, sem vínculo de tabela com a Key:

| Entidade | Usada para | Quando |
|---|---|---|
| Asset (TF2) | Converter o custo da trade em euros | No registro da key (`individual_cost`) |
| Fee | Calcular income líquido, `min_api` e preços-alvo | Em todo cálculo de preço e margem |

## Ciclo de vida de uma Key

| Estado | Marcado por | O que acontece enquanto está aqui | Como sai |
|---|---|---|---|
| **Comprada** | `acquired_at` | `min_api` recalculado todo dia às 07:30 | Auto-sell lista na Gamivo → grava `listed_at` |
| **Listada** | `listed_at` | Reprecificada a cada minuto, numa passada só — sobe se já somos os mais baratos, desce se não somos | Venda confirmada na Gamivo → grava `sold_at` |
| **Vendida** | `sold_at` | `sale_profit` e `sale_profit_percent` calculados | Estado final |

Ver [AUTOMATIONS.md](AUTOMATIONS.md) para os critérios exatos de quando uma key sai de "Comprada" para "Listada", e [docs/adr/0002](../adr/0002-fifo-grouping-by-marketplace-product.md) para por que keys do mesmo produto entram juntas numa única oferta.

## Limites de preço vs. preço anunciado

| O que você vê | O que é |
|---|---|
| Min. API / Max. API na tela de Keys | **Limites** dentro dos quais a reprecificação pode mover o preço — nunca o preço anunciado |
| Preço anunciado na Gamivo | Não existe no sistema: só no painel do marketplace |

Com concorrente, o preço fica logo abaixo do alvo, respeitando os dois limites. **Sem concorrente utilizável**, o teto deixa de valer e o preço passa a ser `market_price × 1,10` da key governante — por isso é normal ver uma key anunciada a €10 com Max. API de €24.

Isso **não** é clamp quebrado: o `max_api` é folga para valorização e só é seguro enquanto existe um concorrente freando o preço. Ele fica intacto no banco e volta a valer sozinho assim que aparece concorrente. Regra e números em [AUTOMATIONS.md](AUTOMATIONS.md#reprecificação-competitiva-updateoffersusecase); decisão de não persistir em [docs/adr/0010](../adr/0010-sole-seller-ceiling-computed-not-persisted.md).

## Bundle vs. Choice

| Tipo | Como é identificado | Comportamento comercial |
|---|---|---|
| `bundle` | Padrão (título não contém "Choice") | Preço despenca no lançamento e leva meses para recuperar |
| `choice` | Título contém "Choice" (ex.: Humble Choice mensal) | Pode ser comprado e vendido de forma mais imediata |

Duas janelas de tempo independentes governam quando uma key de jogo em bundle pode ser vendida:

| Desde o lançamento | Situação | Efeito |
|---|---|---|
| 0 – 21 dias | Bundle em cartaz, preço em queda livre | Key **não** entra na listagem automática |
| ~4 meses+ | Preço tende a já ter recuperado | Momento em que a compra feita no lançamento se paga |

A janela de 21 dias bloqueia só a **venda** — na verdade é o oposto para a compra: a queda no lançamento é justamente o que torna o bundle uma boa oportunidade de aquisição. Tabela completa em [AUTOMATIONS.md](AUTOMATIONS.md#janela-de-bundle).
