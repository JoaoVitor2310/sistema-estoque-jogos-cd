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
| 7 | Receber as keys | O supplier preenche `key_code`, região e validade por linha, mais o total de TF2 (obrigatório para enviar), no link com código que a trade já traz; ou a equipe transcreve do chat | `/deliveries/{uuid}` |
| 8 | Conferir | A entrega sobe ao topo de Abertas; a equipe revisa antes de importar | aba de Trades |
| 9 | Importar as keys da trade | Entrada no estoque, com `individual_cost` rateado pelo lote — **único** caminho de entrada de keys | `POST /trades/{trade}/import` → `RegisterKeyUseCase` |

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
- Importar as keys por uma trade (`POST /trades/{trade}/import`) grava o `trade_id` nas keys e marca a trade como `is_imported` — ela sai da view padrão (Abertas) da aba de Trades mas **permanece no banco** (não é excluída), para o vínculo `trade_id` seguir válido; continua acessível pelas views **Importadas** / **Todas**. Todo card da aba nasce com a tabela de jogos fechada (o cabeçalho fica à vista) e busca suas linhas ao ser aberto — ver [`docs/adr/0009`](../adr/0009-trade-lines-loaded-on-demand.md).
- A importação é **tudo ou nada**: se qualquer key do lote falhar, nenhuma é cadastrada e a trade continua na aba, com todos os erros marcados de uma vez nas linhas correspondentes — evita reimportar em partes e um rateio de custo calculado sobre um lote incompleto.
- O import **lê as linhas gravadas**, não o que está na tela: a requisição não leva corpo. A aba grava o que estiver no debounce do autosave antes de disparar, para não importar sem a correção recém-digitada. As condições que fazem o lote inteiro ser recusado estão em [`docs/PRODUCT.md`](../PRODUCT.md) (seção "Prontidão para importar").
- **Trocar o nome ou a região de uma linha apaga o `gamivo_id` dela**, dos dois lados (aba e página do supplier): o id endereça o par jogo+região, e mudar um dos dois no meio da negociação quase sempre é trocar o jogo tradado. Um id **diferente** na mesma gravação é respeitado; retoque de caixa/espaço não conta. Regra e motivo em [`docs/PRODUCT.md`](../PRODUCT.md) ("ID Gamivo de uma linha").
- O passo 7 pelo link é **opcional**: a trade cujo link nunca foi mandado segue sendo preenchida pela equipe na aba, como sempre foi. O que o link muda é quem digita, não o que o import lê.

### Entrega pelo supplier (passos 7–8)

Detalhe da decisão em [`docs/adr/0008`](../adr/0008-supplier-fills-trade-through-tokenised-link.md).

| Estado da trade | Como se reconhece | O que a equipe vê |
|---|---|---|
| Em negociação | `delivered_at` nulo | faixa com link e código, sem badge |
| Aguardando conferência | `delivered_at` preenchido, `is_imported = false` | topo de **Abertas** + contagem no filtro |
| Importada | `is_imported = true` | badge "Importada"; a faixa some e o link responde "entrega concluída" |

Toda trade nasce com link e código — ter credencial **não** é um estado, e ter link não quer dizer que ele foi mandado. Quem registra o envio é o checkbox "Mensagem enviada", que a aba já tinha.

**Pontos que costumam escapar:**
- O supplier **não cria nem apaga linha** — o conjunto é da equipe. Jogo de brinde e jogo que ele não tem mais vão no recado livre, que aparece no card da trade.
- Ele **nunca enxerga** `market_price`, popularidade nem `gamivo_id`: é a pesquisa do `price_researcher`, e é quanto o jogo dele vale para nós. O **bundle** é a exceção: chega pré-preenchido pela nossa busca e ele pode corrigir — quem teve a key na mão sabe melhor de onde ela veio, e a origem é o que costuma explicar o region lock.
- **A equipe é avisada por e-mail** (`TradeDeliveredMail` → `ADMIN_EMAIL`) assim que ele entrega, com a contagem de keys preenchidas e o recado dele. As keys não vão no e-mail — quem confere abre a aba.
- **Entregar fecha a página para escrita.** A partir do clique o servidor responde 409 a qualquer gravação dele — linha, campo da trade ou um segundo entregar. A página continua abrindo, em leitura, para ele conferir o que mandou; correção depois disso é a equipe que faz, pela aba interna. Não existe "reabrir entrega". O motivo é a janela até o import: enquanto ela dura, as keys entregues são a única cópia que existe.
- O link e o código ficam **à vista na faixa do card**, cada um copiável sozinho — a conversa quase nunca leva os dois na mesma mensagem — e **Enviar acesso** copia a mensagem pronta em inglês com os dois. **Uma trade, um código:** não há como emitir outro, então link vazado só deixa de valer no import.
- Entregar pede confirmação, e a confirmação diz quantas linhas vão em branco — informa sem bloquear, porque linha em branco é resposta legítima — e avisa que o clique fecha a página. **Só há um envio:** depois dele o botão vira aviso de que a entrega será conferida, e os campos ficam em leitura.
- A tabela de keys **não cabe na largura de um celular**, e a barra de rolagem do toque é invisível até o dedo encostar. Por isso a página avisa, medindo o overflow real: uma faixa acima da tabela pede o gesto lateral e nomeia as colunas que ficaram fora (Region, Expires, Bundle), e uma sombra na borda direita marca que a tabela continua. As duas somem quando ele chega ao fim — e nem aparecem na tela larga, onde tudo já cabe.
- O link e o código da faixa do card ficam **indisponíveis** quando o token guardado não abre com a `APP_KEY` do ambiente (um dump aberto fora de produção): o link continua copiável, o código vira aviso. Ver [`security-and-guardrails`](../agents/security-and-guardrails.md).
- A **validade** é escrita e lida em `mm/dd/aaaa` na página dele e em `dd/mm/aaaa` na aba da equipe. O formato vem de `TradeLineAuthority::dateFormat()`; a coluna é `date` e não guarda formato nenhum.

## Fluxo de venda

Do estoque comprado até a venda confirmada e conciliada.

| # | Etapa | Quando roda | Job |
|---|---|---|---|
| 1 | Recalcular o piso de preço | diário, 07:30 | `RegulateMinApiUseCase` |
| 2 | Listar keys elegíveis | **manual** | `gamivo:auto-sell` (`AutoSellUseCase`) |
| 3 | Reprecificar contra concorrentes | a cada minuto, passada única (sobe e desce) | `UpdateOffersUseCase` + `ComparisonAlgorithm` |
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

### O que acontece dentro da baixa de vendas (passo 4)

| # | Sub-etapa | Detalhe |
|---|---|---|
| 4.1 | Paginar o histórico | Janela de 30 dias, status `COMPLETED`, 25 linhas por página |
| 4.2 | Agrupar por pedido | A Gamivo devolve **uma linha por oferta vendida** — várias podem dividir o mesmo `order_id`, até em páginas diferentes |
| 4.3 | Buscar as keys entregues | Um `order-details` por pedido, que traz as keys de **todas** as ofertas dele |
| 4.4 | Casar linha ↔ key | Pelo `product_id` da linha contra o `gamivo_id` da key; cada linha consome `quantity` keys |
| 4.5 | Ratear o líquido | Cada key fica com o líquido da **sua** oferta; a taxa de mediação sai uma vez do pedido |
| 4.6 | Gravar a venda | `sold_at`, `sold_price`, `sale_profit` e `sale_profit_percent`; key já vendida nunca é sobrescrita |

O passo 4.4 casa **linha a linha**: uma key fora da base não custa às outras o valor da própria oferta. Só o que sobra sem par é dividido por igual, e a linha que sobra sem key nenhuma para receber devolve o pedido inteiro ao rateio igual. Cada pedido é contado por como foi atribuído (`orders_by_attribution` no resumo do scheduler) e todo caso diferente de `matched` gera `Log::warning` no canal `schedulers`. Tabela completa dos casos, endpoints e arredondamento em [`docs/GAMIVO.md`](../GAMIVO.md#baixa-de-vendas-uma-linha-de-histórico-por-oferta).
