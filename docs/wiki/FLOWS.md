# Fluxos de negócio

Detalhe de regras em [`docs/PRODUCT.md`](../PRODUCT.md). Esta página descreve a sequência de cada fluxo, do caminho normal para as exceções.

## Fluxo de compra

Da identificação de um fornecedor até a key entrar no estoque.

| # | Etapa | O que acontece | Onde vive |
|---|---|---|---|
| 1 | Identificar o supplier | Perfil na SteamTrades; captura dos jogos da seção *"I have"* | manual |
| 2 | Pesquisar preços | Busca preço e popularidade de cada jogo da lista | `price_researcher` (serviço externo) |
| 3 | Avaliar lucratividade | Calcula o income líquido após taxas Gamivo e quantas TF2 Keys oferecer | `ProspectSupplierUseCase` + `OfferCalculator` |
| 4 | Decidir se comenta | Se vale (re)comentar na lista do supplier | `CommentPolicy` |
| 5 | Registrar a trade | Persiste a lista ofertada e a data do comentário | `Trade` |
| 6 | Negociar | Acerto final de preço com o supplier | manual, na Steam |
| 7 | Importar as keys da trade | Entrada no estoque, com `individual_cost` rateado pelo lote — **único** caminho de entrada de keys | `POST /trades/{trade}/import` → `RegisterKeyUseCase` |

### Quando o fluxo para antes do fim

| Situação | O que acontece |
|---|---|
| Nenhum jogo da lista é lucrativo | Descarta — não comenta |
| Jogos não mudaram **e** faz < 14 dias do último comentário | Não recomenta (`CommentPolicy::INTERVAL_DAYS`) |

### Margens de compra

| Contexto | Margem-alvo | Divisor aplicado |
|---|---|---|
| Padrão | 100% de lucro (dobrar o investido) | ÷ 2,0 |
| Negociação mais competitiva | 80% | ÷ 1,8 |
| Negociação mais competitiva | 60% | ÷ 1,6 |
| Prospecção **automática** de supplier novo | 70% (`OfferCalculator::NEW_SUPPLIER_PROFIT_PERCENT`) | ÷ 1,7 |

**Pontos que costumam escapar:**
- Os 70% valem só na prospecção automática — a negociação manual usa a margem que fizer sentido no caso.
- `individual_cost` sai do rateio do lote inteiro da trade (proporcional ao income de cada jogo). Se o `market_price` de uma key for editado depois, o custo e os lucros de compra do **lote inteiro** são recalculados (ver [docs/adr/0004](../adr/0004-recalculate-trade-on-key-edit.md)).
- Importar as keys por uma trade (`POST /trades/{trade}/import`) grava o `trade_id` nas keys e marca a trade como `is_imported` — ela sai da view padrão (Abertas) da aba de Trades mas **permanece no banco** (não é excluída), para o vínculo `trade_id` seguir válido; continua acessível pelas views **Importadas** / **Todas** (card colapsado).
- A importação é **tudo ou nada**: se qualquer key do lote falhar, nenhuma é cadastrada e a trade continua na aba, com todos os erros marcados de uma vez nas linhas correspondentes — evita reimportar em partes e um rateio de custo calculado sobre um lote incompleto.

## Fluxo de venda

Do estoque comprado até a venda confirmada e conciliada.

| # | Etapa | Quando roda | Job |
|---|---|---|---|
| 1 | Recalcular o piso de preço | diário, 07:30 | `RegulateMinApiUseCase` |
| 2 | Listar keys elegíveis | **manual** | `gamivo:auto-sell` (`AutoSellUseCase`) |
| 3 | Reprecificar contra concorrentes | 5 min (somos os + baratos) / 1 h (não somos) | `UpdateOffersUseCase` + `ComparisonAlgorithm` |
| 4 | Dar baixa nas vendas | 2×/dia (06:00 e 18:00) | `UpdateSoldOffersUseCase` |

A ordem importa: o passo 1 precisa rodar **antes** do 2, porque o auto-sell só consulta o `min_api` já gravado — nunca recalcula.

### O que acontece dentro da listagem (passo 2)

| # | Sub-etapa | Detalhe |
|---|---|---|
| 2.1 | Filtrar keys elegíveis | Tem `gamivo_id`, não listada, não vendida, não é gift link, jogo fora da janela de 21 dias de bundle |
| 2.2 | Agrupar por produto | Keys do mesmo `gamivo_id` compartilham uma única oferta |
| 2.3 | Aprovar key a key | Entra quem tem o preço de mercado cobrindo o **próprio** `min_api` |
| 2.4 | Eleger a governante | A mais antiga (menor `id`) entre as aprovadas — define o preço único da oferta |
| 2.5 | Criar a oferta e subir as keys | Um `uploadKeys` em lote, ordem de `id` ASC |
| 2.6 | Marcar `listed_at` | Só nas keys confirmadas na oferta |

Tabelas completas de critérios, tiers e cenários de reprecificação em [AUTOMATIONS.md](AUTOMATIONS.md).

### Quando uma key não é listada

| Situação | O que acontece |
|---|---|
| Reprovada na elegibilidade (2.1) | Pulada — reavaliada na próxima rodada |
| Mercado abaixo do `min_api` dela (2.3) | Pulada **individualmente** — não bloqueia as outras keys do mesmo produto |
| Aprovada mas não confirmada na oferta (2.6) | Continua elegível — tenta de novo na próxima rodada |

**Por que uma "governante"?** A Gamivo vende por ordem de chegada (FIFO) dentro de uma oferta, e a oferta tem um preço só — então só faz sentido a primeira key da fila definir esse preço. Ver [`docs/adr/0002`](../adr/0002-fifo-grouping-by-marketplace-product.md).

**`gamivo:auto-sell` é manual** — não existe cron chamando o `AutoSellUseCase` hoje. Mapa completo de agendamentos em [AUTOMATIONS.md](AUTOMATIONS.md).
