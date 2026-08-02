# O fechamento mensal registra em vez de calcular, e a verba de TF2 vira conta real

O `FinancialMonth` deixa de ter uma cascata de fechamento. Cada movimentação de dinheiro — separar a verba de TF2, pagar despesas, abastecer as caixinhas, sacar para os sócios — passa a ser um **lançamento explícito** que o usuário confirma no momento em que a coisa acontece na vida real. O `close` só encerra o mês e abre o próximo. Junto com isso, a verba mensal de TF2 deixa de ser um valor virtual e vira a **quarta conta** do domínio (`AccountType::Tf2`): o dinheiro sai do Principal de verdade, as compras de TF2 debitam dessa conta, e o que sobrar volta ao Principal no fechamento.

Isto **reverte** duas regras anteriores: (1) "Reserva TF2 = earmark virtual — subtraída no cálculo e na base dos %, mas o dinheiro permanece no Principal" e (2) a cascata determinística que, ao fechar, somava as entradas, descontava reserva e despesas, aplicava 20%/10% e dividia o resto entre os sócios. Ambas foram validadas com o dono do produto na spec original, e ambas caíram na primeira simulação de uso real.

## Por que caiu

A cascata não estava errada na aritmética — estava no lugar e no tempo errados. Ela executava **no fim do mês** o que na prática acontece **no começo**: os sócios recebem o saque da Gamivo, separam a verba de compra, pagam os custos fixos, abastecem as caixinhas e sacam a parte de cada um; só *depois* disso, ao longo do mês inteiro, é que compram as TF2 keys. Um botão que fazia tudo isso de uma vez, no encerramento, invertia a ordem real e tirava do usuário a confirmação de cada passo.

O earmark virtual tinha um defeito próprio e mais grave: **não havia como gastar dele**. As compras reais de TF2 debitavam o Principal, e a reserva existia apenas como uma subtração dentro do cálculo. Não existia resposta para "quanto ainda resta da verba deste mês" que não fosse recomputar filtrando categorias, e estourar a verba era invisível — o Principal simplesmente encolhia, sem nada indicando que o orçamento tinha sido ultrapassado.

## A ordem substitui a cascata

Quando o lançamento usa porcentagem, ela incide sobre o **saldo atual da conta de origem**. Isso é o que permite remover a cascata sem perder o resultado que ela produzia: seguindo a ordem natural do mês, o saldo do Principal no momento de abastecer o Reinvestimento *já é* o antigo "saldo base" (as entradas menos a verba de TF2 e menos as despesas, porque esses lançamentos vieram antes). Ao lançar a Emergência em seguida, o Principal já está pós-reinvest — de novo, a mesma base de antes.

Ou seja: a sequência de bases que a cascata codificava passa a emergir da ordem em que os lançamentos são feitos, sem o sistema precisar rastrear degrau nenhum. Por isso a ordem dos passos é **roteiro e não invariante** — a tela orienta, mas nada bloqueia, e lançar fora de ordem produz saldos corretos, apenas com bases diferentes das habituais.

## Considered Options

- **Manter a cascata e só reordenar a tela** — trata o problema como apresentação. Rejeitado: não resolve o earmark, que continuaria sem permitir gasto nem mostrar quanto resta da verba, e continuaria decidindo sozinho o destino do dinheiro.
- **Manter o earmark virtual e exibir um "resta da verba" derivado** — indicador calculado somando compras de TF2 do mês contra a meta. Rejeitado: é um número sem lastro no extrato, que depende de filtrar categorias para reconciliar, e o estouro do orçamento continuaria sem representação real.
- **Verba de TF2 como quarta conta** — escolhido. Reaproveita toda a mecânica de saldo derivado que já existe, torna "quanto resta" o próprio saldo da conta, e transforma o estouro em algo visível (saldo negativo).
- **Verba de TF2 como tabela/conceito próprio de orçamento** — evitaria a assimetria do carry-forward (a conta TF2 não carrega o próprio saldo, devolve ao Principal). Rejeitado: duplicaria a mecânica de saldo e exigiria caminho próprio para as compras debitarem dela.

## Consequences

- **O `close` gera exatamente um movimento:** o `transfer` de devolução da sobra do TF2 para o Principal, no mês que encerra. Nada mais é gerado automaticamente. Com isso o TF2 fecha em zero e o carry-forward fica uniforme — toda conta abre o mês seguinte com o próprio saldo.
- **Saldo negativo é permitido em qualquer conta**, sinalizado visualmente. Comprar acima da meta é decisão de negócio legítima, e o desenho depende disso: uma verba estourada fecha o mês com saldo negativo, cuja "devolução" vira débito no Principal — exatamente o efeito correto. Bloquear o negativo tornaria esse caminho inalcançável.
- **As 9 colunas de snapshot da cascata são removidas** (`total_income` … `partner_two_amount`), junto de `FinancialMonthCalculator` e `FinancialMonthResult`. Os totais do mês passam a ser derivados dos movimentos, coerente com a regra que já vigorava no domínio: saldo nunca é persistido. Sobrevive apenas a divisão entre sócios, extraída para o Domain `PartnerSplit` — a garantia de que sócio 1 + sócio 2 reconcilia ao centavo, com o órfão indo para o Sócio 1, continua valendo.
- **As porcentagens (20/10/50) viram apenas prefill de formulário**, herdadas mês a mês. Nunca são aplicadas sozinhas; existem só para o usuário não redigitar os valores de sempre. O mesmo vale para a meta de TF2, que passa a viver no próprio movimento `tf2_allocation` (com `quantity` × `unit_price`) e é pré-preenchida a partir do mês anterior — o incremento automático de +10 por mês foi eliminado.
- **Apagar um lançamento exige apagar o grupo inteiro.** Uma transferência grava duas linhas (débito na origem, crédito no destino); remover só uma criaria ou destruiria dinheiro. Por isso os movimentos nascidos do mesmo lançamento compartilham um `group_id`, e a exclusão opera sobre o grupo — apenas em mês `draft`, já que mês `closed` é imutável.
- **Nada disso migra dados.** A implementação da spec anterior nunca chegou a ser commitada nem deployada, e as tabelas em produção estão vazias; as colunas mortas são removidas por migration nova, sem backfill.
