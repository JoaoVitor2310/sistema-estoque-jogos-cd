# Sistema Estoque Jogos CD

Inventário e automação para trading de keys de jogos digitais — do registro da compra até a venda automática e a reprecificação competitiva no marketplace Gamivo.

## Language

### Keys e precificação

**Key**:
Uma chave de jogo digital comprada e/ou vendida — a unidade central de estoque em torno da qual gravitam preço, listagem e trade.
_Avoid_: licença, item, código (isoladamente).

**Governante**:
Entre as keys que compartilham a mesma oferta (mesmo produto no marketplace), a mais antiga — define os limites de preço (`min_api`/`max_api`) da oferta inteira, pois o marketplace vende por ordem de chegada (FIFO). Antes de listada (decisão do `AutoSellUseCase`, entre as aprovadas para entrar), "mais antiga" é a de menor `id` — ainda não existe `listed_at` pra comparar. Depois de listada (reprecificação do `UpdateOffersUseCase`, entre as já na oferta), "mais antiga" é a de menor `listed_at`, com `id` como desempate (`listed_at` é `date`, sem hora — keys do mesmo lote empatam).
_Avoid_: key primária, key líder.

**min_api / max_api**:
Os limites inferior e superior dentro dos quais o motor de reprecificação automática do marketplace pode mover o preço de uma key.
_Avoid_: piso / teto isoladamente (min_api/max_api são os termos usados no dia a dia).

**Key velha**:
Uma key comprada há muito tempo sem vender — seu piso de preço é permanentemente rebaixado (não volta a subir mesmo que a key seja listada depois) e, uma vez listada, seu teto fica travado no preço praticado. A key parada é dinheiro parado: melhor vender mais barato e reinvestir o capital em novas compras do que segurar o estoque esperando um preço melhor que pode nunca vir.
_Avoid_: key parada, key expirada (conceito diferente — ver Key expirando).

**Limbo**:
Uma key listada há muito tempo sem vender — recebe o mesmo piso permanente que a key velha, e pela mesma razão (capital parado), mas contado a partir da data de listagem, não da compra.
_Avoid_: key travada.

**Key expirando**:
Uma key cuja data de expiração está próxima — piso de preço rebaixado incondicionalmente, independente de custo ou idade, para forçar a venda antes de perder o valor.
_Avoid_: key expirada (já passou do prazo — conceito distinto, não coberto hoje).

**Gift link**:
Uma key cujo código é uma URL de resgate, não um código de ativação direto — nunca elegível para listagem automática.
_Avoid_: key de link.

### Bundles

**Bundle**:
Um conjunto de jogos vendidos juntos por um preço único. Quando um jogo é lançado dentro de um bundle, seu preço de mercado despenca; ele volta a subir em até ~4 meses. É exatamente essa queda inicial que torna o lançamento de um bundle uma boa oportunidade de **compra** — o jogo fica temporariamente barato antes do preço se recuperar.
_Avoid_: pacote, coleção.

**Janela de exclusão do bundle**:
Período após o lançamento de um bundle durante o qual keys dos jogos daquele bundle não são listadas automaticamente para **venda** — o preço ainda está em queda livre, então vender agora significaria vender na baixa. Não impede a compra, só a venda.
_Avoid_: cooldown, período de graça.

**Choice**:
Tipo de bundle (Humble Choice) identificado pelo título — tratado separadamente do bundle comum.
_Avoid_: bundle tipo 2.

### Precificação competitiva

**Price dumper**:
Um concorrente cujo preço listado está anomalamente abaixo do segundo colocado — é ignorado ao calcular o preço-alvo, para não perseguir um preço irreal.
_Avoid_: lowballer, concorrente barato.

**Concorrente com bot**:
Um vendedor conhecido por rodar precificação automática própria — quando ficamos em 2º atrás dele, o sistema mira no 3º colocado em vez de entrar numa guerra de preços com o bot.
_Avoid_: bot, vendedor automático.

**Wholesale**:
Modo de venda no atacado — o comprador leva mais de 10 unidades de uma vez, pagando menos por jogo, e o marketplace cobra uma taxa fixa menor sobre a venda do que cobraria no modo retail padrão.
_Avoid_: modo atacado (isolado, sem explicar o mecanismo), bulk.

### Jogos e ativos

**Steam ID não resolvido**:
Um jogo cujo `steam_id` ainda não foi descoberto. Dois casos que parecem iguais, mas não são: **nunca procurado** (elegível para uma nova busca) e **procurado sem sucesso** (o SteamCharts não conhece o jogo — procurar de novo não muda nada). Sem essa distinção, a busca recorrente reprocessaria os mesmos jogos ausentes para sempre.
_Avoid_: jogo sem steam_id, jogo não encontrado.

**Variação do dólar**:
O afastamento entre o preço em dólar guardado para o TF2 e o que a conversão atual do preço em real devolve. Ultrapassado o limiar, o sistema alerta para que os preços do ativo sejam reavaliados manualmente — quem corrige é uma pessoa, porque trocar o preço do ativo muda o custo calculado de toda trade nova.
_Avoid_: câmbio, desvio, cotação.

### Suppliers e Trades

**Supplier**:
Um perfil Steam de onde keys são obtidas via troca — rastreado independentemente de já ter havido troca (`has_traded`) ou de ter sido curado manualmente (`is_added`).
_Avoid_: fornecedor (termo antigo do banco), vendor.

**Trade**:
Uma lista de jogos comentada/ofertada a um supplier em um momento específico.
_Avoid_: vip list, lista (isoladamente).

**Prospecção**:
Avaliar a lucratividade dos jogos oferecidos por um supplier e decidir se vale comentar (de novo) naquela lista.
_Avoid_: scouting.

**Trade em estoque**:
Uma trade é considerada "em estoque" quando pelo menos um dos `key_code` ofertados já está presente em alguma Key do estoque — ou seja, já compramos aquele jogo daquele lote.
_Avoid_: trade concluída, trade fulfilled.

**Linha da trade**:
Um jogo dentro de uma trade, com o preço de mercado pesquisado, a região, a validade e o `key_code` correspondente. É a unidade que o supplier preenche na entrega e que vira uma Key no import.
_Avoid_: item, row, game (isoladamente — `Game` é o catálogo, outra coisa).

**Linha preenchida**:
Linha da trade que tem nome do jogo **ou** preço de mercado. É o que separa dado do operador de rascunho: a aba mantém linha em branco como estado normal, e só a linha preenchida é cobrada por `key_code` e importada.
_Avoid_: linha válida, linha completa (completa é a que já passou pela **prontidão para importar**).

**Prontidão para importar**:
Condição da trade inteira para virar keys: toda linha preenchida com nome, preço > 0 e `key_code`, mais quantidade de TF2 e fornecedor na trade. Recusada, nenhuma key entra — a importação é tudo ou nada.
_Avoid_: trade válida, trade completa.

**Entrega**:
O ato do supplier de repassar os `key_code` negociados de uma trade, junto da região, da validade e do total de TF2 acertado. Não confundir com **trade em estoque**: entregue significa que ele mandou os códigos; em estoque significa que já importamos as keys.
_Avoid_: preenchimento, submissão, envio.

### Fechamento mensal

O caixa da empresa em R$, montado lançamento a lançamento. Não confundir com a **aba Financeiro** (`/sales`), que é análise de vendas em € — domínios distintos que compartilham só o prefixo do nome.

**Fechamento mensal** (`FinancialMonth`):
Um mês do livro-caixa dos sócios, do estado `draft` (sendo montado) até `closed` (histórico imutável). É a entidade; "fechar" e "reabrir" são atos sobre ela, não estados.
_Avoid_: mês financeiro, período, competência.

**Conta** (`AccountType`):
Um dos quatro baldes onde o dinheiro da empresa vive — Principal, TF2, Reinvestimento e Emergência. Nenhuma guarda saldo próprio: o saldo é sempre a soma dos movimentos do mês.
_Avoid_: carteira, bucket, caixa (isoladamente — "caixa" é o Principal).

**Caixinha**:
As contas de Reinvestimento e Emergência, as duas que exigem justificativa para serem debitadas. Termo falado pelos sócios.
_Avoid_: reserva, fundo, poupança.

**Lançamento**:
Uma ação do usuário que move dinheiro. Vira **uma ou mais linhas** no livro-caixa: uma transferência grava a saída e a entrada, uma distribuição grava um débito por sócio. É a unidade de correção — apagar remove o lançamento inteiro.
_Avoid_: movimento (isoladamente — movimento é a linha, não a ação).

**Perna**:
Cada linha individual de um lançamento (`FinancialMovement`). As pernas de um mesmo lançamento compartilham `group_id` e somem juntas.
_Avoid_: partida, entrada, leg.

**Categoria de gasto** (`ExpenseCategory`):
A natureza de uma perna de saída — Compra de Jogo, Impostos, Assinaturas ou Outros. Existe para permitir agrupar gastos por tipo (ex: um dashboard futuro); não confundir com o `category` do lançamento (`MovementCategory`), que classifica a perna em si (income/expense/transfer/...), não o que ela representa.
_Avoid_: tipo de despesa, subcategoria.

**Categoria de receita** (`IncomeCategory`):
A origem de uma perna de entrada — Saque Gamivo, Investimento externo, Rendimentos ou Outros. Mesmo papel que a Categoria de gasto, do lado da receita; os dois enums não compartilham valores.
_Avoid_: fonte de receita, subcategoria.

**Verba de TF2** (`tf2_allocation`):
O dinheiro separado no início do mês para comprar TF2 keys — sai do Principal e passa a viver na conta TF2. A meta (quantidade × preço unitário) mora no próprio lançamento. Não confundir com **compra de TF2** (`tf2_purchase`), que é a compra real debitando essa verba.
_Avoid_: meta de TF2, orçamento, reserva de TF2.

**Sobra da verba**:
O que restou na conta TF2 no fechamento e volta ao Principal. Se a verba foi estourada (saldo negativo), a "devolução" troca de origem e vira débito no Principal.
_Avoid_: saldo residual, troco.

**Saque de sócio** (`partner_distribution`):
A retirada dos dois sócios num único lançamento — dois débitos na mesma conta, identificados por posição (`partner_slot` 1 ou 2). O sistema não guarda nome de sócio.
_Avoid_: distribuição de lucros, pró-labore, dividendo.

**Centavo órfão**:
O centavo que sobra numa divisão ímpar entre os sócios. Fica sempre com o Sócio 1, para que a soma reconcilie exatamente com o total sacado.
_Avoid_: arredondamento, diferença.

**Saldo de abertura** (`opening`):
O movimento que traz o saldo de uma conta do mês anterior para o corrente. Nasce do fechamento (ou do bootstrap) e não é apagável à mão — é a única memória do dinheiro que atravessou a virada do mês.
_Avoid_: saldo inicial, carry-over.

**Movimento gerado** (`is_generated`):
Linha que o sistema lançou sozinho — hoje só a devolução da verba de TF2 no fechamento. Quem a desfaz é o `reopen`, em bloco, nunca a exclusão manual.
_Avoid_: movimento automático, movimento de sistema.
