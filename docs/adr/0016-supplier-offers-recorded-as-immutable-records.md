# A oferta ao supplier é gravada como registro imutável, uma linha por rodada

**Status:** accepted — tabelas, cálculo e gravação da primeira oferta implementados em 2026-10-29. Ainda nenhum fluxo grava oferta: quem vai chamar `TradeOfferService::recordInitial` é a criação de trade pela integração com o chatbot. Plano em [`docs/IMPLEMENTACAO_CHATBOT.md`](../IMPLEMENTACAO_CHATBOT.md).

Até aqui o sistema não guardava quanto se ofertou a um supplier. A aba Trades calcula as colunas 100/80/60% no navegador, a prospecção calcula a oferta só para o comentário, e o valor negociado ficava na conversa da Steam. Com o chatbot conversando com o supplier, o Estoque passa a ser **o dono da oferta**: decide o valor de cada rodada e o chatbot só o transmite — e, como o chatbot não guarda cópia, a oferta precisa estar gravada aqui.

A decisão: cada oferta feita é **um registro em `trade_offers`**, com o valor de cada jogo em `trade_offer_lines`. O registro é **imutável** — uma nova rodada de negociação cria outra oferta, e a vigente é a de rodada mais alta (`Trade::currentOffer`). Os models recusam a gravação de uma alteração.

A negociação tem **no máximo 3 rodadas** (a oferta inicial e duas contrapropostas, `SupplierOffer::MAX_ROUNDS`), e a 3ª é a **final**, seja qual for o valor dela. A rodada é coluna (`trade_offers.round`), com índice único por trade.

O critério é o do [ADR 0005](0005-financial-month-records-instead-of-calculating.md): uma oferta enviada é um **fato** com a cotação da TF2 daquele momento, não um estado que se recalcula. Recalcular a oferta a cada leitura faria o supplier ver um valor diferente cada vez que a cotação mudasse; sobrescrevê-la apagaria o que ele leu na primeira renegociação.

O valor de cada jogo sai de `App\Domain\Pricing\SupplierOffer`, a mesma peça que a prospecção usa: o lucro pedido (o da categoria do supplier, em `SupplierCategory::initialProfitPercent`), **nunca acima da oferta máxima** — o maior custo em que a key ainda nasce dentro da margem inicial do min_api (`MinimumMarginPolicy::maxInitialCost`, a mesma régua do min_api, sem cópia das faixas). Encalhado e giveaway ficam fora da oferta, como na prospecção ([ADR 0013](0013-flag-overstocked-games-in-trades.md) e [ADR 0014](0014-record-steam-giveaways-from-gamerpower.md)).

## Considered Options

- **Tabela própria, imutável, uma linha por rodada** — escolhido.
- **Colunas em `trade_lines` (`offer_price`)** — rejeitado. Uma oferta por linha, sobrescrita na primeira renegociação: perde o que o supplier leu e a cotação usada. E `trade_lines` já recebe escrita do supplier pela página da entrega ([ADR 0008](0008-supplier-fills-trade-through-tokenised-link.md)); a oferta passaria a dividir a linha com um segundo escritor.
- **Gravar só o percentual e calcular o valor na leitura** — rejeitado. A cotação da TF2 muda entre duas leituras, então o mesmo percentual daria valores diferentes ao mesmo supplier.
- **O chatbot escolher o percentual e o Estoque só calcular** — rejeitado. Espalharia a regra de oferta entre dois sistemas; quem tem a cotação, as taxas e o min_api é o Estoque.

## Consequences

- **A linha da oferta guarda cópia do jogo e da região.** `trade_offer_lines.game_name` e `region` são o que o supplier leu, não referência: ele edita a região da linha da trade na página da entrega, e a oferta tem de continuar dizendo o que foi ofertado. `trade_line_id` liga as duas enquanto a linha existir (`nullOnDelete`).
- **Grava-se só o que não se recalcula.** A oferta é por jogo: cada linha guarda o valor lido (`price`). O total é a soma desses valores já arredondados (`TradeOffer::totalPrice()`), e "final" é a rodada (`TradeOffer::isFinal()`) — nenhum dos dois vira coluna, para não haver duas versões do mesmo fato. A oferta exata em TF2 e a oferta máxima só existem durante o cálculo: a rodada seguinte parte do valor lido, com a cotação do momento.
- **A oferta guarda a cotação usada** (`tf2_rate`: quanto vale 1 TF2 key na moeda da oferta), porque o sistema só guarda a cotação atual e é ela que explica o valor de cada jogo depois.
- **A oferta é final pela rodada, não pelo valor.** Se fosse final ao chegar à oferta máxima, o supplier que insiste sempre arrancaria o menor lucro possível e aprenderia onde ele fica. A oferta máxima continua como limite de cada linha: nos lucros de categoria atuais (60% a 80%) ela não é atingida na primeira oferta — a maior margem inicial é 55% —, e só limita as contrapropostas.
- **A rodada única por trade barra a primeira oferta duplicada.** Um callback repetido não grava duas rodadas 1; `recordInitial` recusa a trade que já tem oferta, e o índice `(trade_id, round)` garante o mesmo no banco.
- **A imutabilidade é do model.** O evento `updating` recusa `save()` numa oferta existente; um `update` pelo query builder não passa por ele. Nenhum código do sistema faz isso, e não vale o custo de travar também no banco.
- **Sem oferta, nada é gravado.** Supplier bloqueado, ou nenhum jogo ofertável, faz `recordInitial` devolver `null` sem escrever. Falta de cotação da TF2 (em euro, de onde toda oferta parte, ou na moeda da trade) é erro, não oferta zerada.
- **A trade continua com `amount`.** A oferta é o que propomos; o `amount` é o que fechamos, informado na página da entrega.
