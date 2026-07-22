# Domínio

Para as definições precisas de cada termo (o que é / o que evitar chamar), veja o glossário em [CONTEXT.md](../../CONTEXT.md). Esta página mostra como as entidades se conectam.

## Entidades

| Entidade | Tabela | Papel |
|---|---|---|
| **Key** | `keys` | Unidade central — uma chave comprada e/ou vendida. Tudo (preço, listagem, trade) gira em torno dela. |
| **Supplier** | `suppliers` | Perfil Steam de onde keys são obtidas via troca. Rastreia `has_traded` (já trocou alguma vez) e `is_added` (curado manualmente) de forma independente. |
| **Trade** | `trades` | Uma lista de jogos comentada/ofertada a um Supplier em um momento específico. |
| **Game** | `games` | Catálogo de jogos — nome, `gamivo_id`, popularidade, preço de referência. |
| **Bundle** | `bundles` | Pacote de jogos vendidos juntos (Humble Bundle, Fanatical, Green Man Gaming...). Tipo `bundle` ou `choice`, resolvido pelo título. |
| **Asset** | `assets` | Ativo de troca (ex.: TF2 Key) com preço em EUR/USD/BRL — usado para converter o custo de uma trade. |
| **Fee** | `fees` | Taxas do marketplace (percentual + fixo, por faixa de preço) — usadas em todo cálculo de income/margem. |

## Relacionamentos

Vínculos reais no banco, começando pelos da Key (a entidade central):

| De | Para | Cardinalidade | Como se ligam |
|---|---|---|---|
| Key | Supplier | N : 1 | `keys.supplier_id` → `suppliers.id` (FK, nullable) |
| Key | Game | N : 1 | `keys.gamivo_id` ↔ `games.gamivo_id` — **join por string, sem FK** |
| Trade | Supplier | N : 1 | `trades.supplier_id` → `suppliers.id` (FK) |
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
| **Listada** | `listed_at` | Reprecificada a cada 5 min (se somos os mais baratos) ou de hora em hora | Venda confirmada na Gamivo → grava `sold_at` |
| **Vendida** | `sold_at` | `sale_profit` e `sale_profit_percent` calculados | Estado final |

Ver [AUTOMATIONS.md](AUTOMATIONS.md) para os critérios exatos de quando uma key sai de "Comprada" para "Listada", e [docs/adr/0002](../adr/0002-fifo-grouping-by-marketplace-product.md) para por que keys do mesmo produto entram juntas numa única oferta.

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
