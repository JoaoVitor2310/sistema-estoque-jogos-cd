# PRODUCT.md — Regras de Negócio

## Visão Geral

O sistema tem como objetivo centralizar informações de jogos, bundles e choices lançados em diferentes lojas, além de apoiar a operação de compra e venda de chaves de jogos com base em preço, margem de lucro, popularidade e tempo de mercado.

As principais frentes do sistema são:

- gerenciamento de keys de jogos, armazenando dados como (preço de compra, lucro esperado, data de expiração);
- gerenciamento de bundles e choices;
- apoio à decisão de compra de jogos;
- apoio à decisão de venda de jogos;
- integração com APIs externas para precificação, popularidade, mapeamento e status de mercado.

---

## TF2 Keys
Principal moeda de troca na plataforma Steam, é importante ter o valor atualizado na aba "Recursos" para realizar as contas com base no valor dela em Euro. TF2 é convertida para euro que é a moeda utilizada para as contas do sistema.

## Keys
Chave de ativação dos jogos, é muito provável que tenhamos mais de 1 key do mesmo jogo, exemplo:
Key: YLXH8-22DWQ-4GD85, jogo: Corner Shop: NightShift
Key: GUXH8-21Z2K-14B0S, jogo: Corner Shop: NightShift

Cada key terá os seus valores individuais, mas o jogo é um valor que pode ser repetido, no exemplo acima os dois tem o mesmo gamivo_id por exemplo.


### Data de Expiração
Data em que a key para de funcionar. Quando faltam 30 dias (`KeyEligibility::EXPIRY_ALERT_DAYS`), é enviado um e-mail de alerta para carcadeals@gmail.com. Nesse mesmo prazo (`EXPIRY_PRICE_FLOOR_DAYS`), a `MinimumMarginPolicy` já rebaixa o `min_api` ao piso (€0,02) para forçar a venda antes da expiração.

### Region lock
Determina a região que um jogo pode ser ativado, exemplo:
Jogo: Deceive Inc. - Region Lock: EU/NA
Significa que esse jogo só pode ser ativado por usuários que estão nessas regiões(fisicamente ou por VPN). A regionalidade influencia o valor final do jogo.


## Trades
Trades é uma compra realizada com nossos fornecedores. Nessa troca pode ter diversos jogos, cada jogo é calculado individualmente e no final é enviado o somatório dos valores dos jogos, resultando no valor da trade. Cada trade é inserida de uma vez no sistema para facilitar, e o Valor Pago Total é um reflexo disso, exemplo:
5.5x TF2 Keys / 8

Significa que foi gasto 5,5 TF2 keys para um trade de 8 jogos. Esses 8 jogos serão enviados de uma única vez, e o valorPagoIndividual vai conseguir calcular o preço de cada jogo.

## Lucro esperado
O lucro inicial considerado é de 100% quando é analisado um jogo para ser comprado, porém isso pode mudar para os seguintes casos:
- Fornecedores de longa data
- Jogos muito caros
- Oportunidade de conhecer novos fornecedores — a prospecção automática (`ProspectSupplierUseCase`) usa 70% de lucro (`OfferCalculator::NEW_SUPPLIER_PROFIT_PERCENT`) para tornar a oferta mais atrativa a fornecedores novos

A ideia é não deixar ficar abaixo de 30%.

## Planilhas de cálculo
Atualmente os preços ofertados aos fornecedores são calculados em planilhas de acordo com o lucro acima; o `OfferCalculator` já automatiza esse cálculo no fluxo de prospecção de fornecedores.

## Custo benefício
Como cada key tem um longo processo a ser feito, o ideal é não comprar jogos abaixo de 1 euro(preco cliente), por não compensar o trabalho e tempo dedicado à aquela key. Isso não é uma regra imutável, mas precisamos definir melhor quando vale a pena ou não comprar.

## Giveaway
Jogos que são oferecidos de maneira gratuita por sites. O preço desses jogos despenca no mercado, mas isso demora umas horas ou dias para acontecer. Se comprarmos um jogo no meio tempo dele sair no giveaway e não sabermos, é quase certo que iremos ter prejuízo.
### https://www.gamerpower.com/api-read - Já descobrimos essa API para saber os jogos lançados em Giveaway.

## Como saber quanto vale o jogo agora
Atualmente só vendemos keys na Gamivo e é lá que o preço atual é verificado. O preço é definido com base no que os concorrentes estão vendendo — sempre abaixo do menor deles (passo de €0,014, `ComparisonAlgorithm::PRICE_STEP`) para ficar mais visível e atraente. Essa lógica hoje é do próprio sistema (`UpdateOffersUseCase` + `ComparisonAlgorithm`), com proteção contra price dumpers e concorrentes com bot.

## Tempo de venda da key
A margem mínima exigida para listar um jogo à venda é calculada pela `MinimumMarginPolicy` e persistida no `min_api` de cada key (recalculado diariamente pelo `RegulateMinApiUseCase` às 07:30). O auto-sell lista a key quando o preço de mercado cobre esse `min_api`.

A margem-base varia por faixa de custo (jogo caro tolera margem menor; jogo muito barato exige margem maior):

| Custo individual | Margem-base |
|---|---|
| < €1 | 55% |
| €1–€10 | 60% (default) |
| €10–€15 | 45% |
| > €15 | 40% |

Essa margem decai com o tempo, e a curva depende de a key já estar listada ou não:

**Não listada** (conta a partir da compra):
- ≥ 4 meses → 40%
- ≥ 6 meses → 15%

**Listada** (conta a partir da listagem):
- ≥ 3 meses → 40%
- ≥ 4 meses → 30%
- ≥ 6 meses → 20%

**Piso incondicional** (vende ao mínimo de €0,02, independente de lucro):
- expira em ≤ 30 dias;
- comprada há ≥ 8 meses (`OLD_KEY_MONTHS`);
- listada há ≥ 10 meses (limbo).

## Valor mínimo que a key pode ficar a venda
O piso é €0,02 (`MinMaxPriceCalculator::FLOOR`), o que faz o jogo sair praticamente sem lucro. Para keys compradas há ≥ 8 meses (`OLD_KEY_MONTHS`), listadas há ≥ 10 meses (limbo) ou expirando em ≤ 30 dias, a `MinimumMarginPolicy` rebaixa o `min_api` a esse piso — a intenção é vender independente do preço e reinvestir o capital em novos jogos. Diferente da versão antiga, esse rebaixamento já é aplicado automaticamente, sem depender de um serviço externo.

## Taxas com problemas em key
Quando uma key tem problema, a Gamivo nos dá a opção de reembolsar o cliente ou fornecer outra chave. Se tivermos outra key, enviaremos para o cliente. Se não tivermos a key, a chave é reembolsada e o dinheiro que ganhamos com essa venda é devolvido para o cliente. Atualmente a Gamivo tem 2 taxas de punição para quando tem problemas com as keys:
1 euro - Aplicada quando uma chave tem problema de region_lock, ou chave duplicada e etc. Mais comum de acontecer por conta de algum descuido dos nossos fornecedores, normalmente os fornecedores reembolsam o preço que pagamos da key e esse 1 euro de taxa.
10 euros - Aplicada quando uma chave foi revogada, ou seja, vendemos para um cliente na Gamivo mas os desenvolvedores revogaram essa chave. Menos comum de acontecer, normalmente acontece quando caímos em golpe e não somos reembolsados da key nem dessa taxa, é muito prejuízo e esse cenário deve ser evitado ao máximo.


## Financeiro

Aba de análise financeira do negócio, acessível em `/financial`. Permite filtrar por mês e ano. Todas as métricas de venda são baseadas em `sold_at` (data de venda); métricas de compra são baseadas em `acquired_at`.

### Cards de KPI (período filtrado)

| Métrica | Origem |
|---|---|
| Lucro líquido | `SUM(sale_profit)` das keys com `sold_at` no mês |
| Receita bruta | `SUM(sold_price)` das keys vendidas no mês |
| Keys vendidas | `COUNT(*)` where `sold_at` no mês |
| Margem média | `AVG(sale_profit_percent)` das vendidas no mês |
| Keys compradas | `COUNT(*)` where `acquired_at` no mês |
| Investido em compras | `SUM(individual_cost)` where `acquired_at` no mês |
| TF2 keys gastas | Soma de `tf2_quantity` agrupada por pares únicos `(total_paid, acquired_at)` para não contar a mesma trade múltiplas vezes |

### Estoque atual (snapshot sem filtro de data)

| Métrica | Origem |
|---|---|
| Total em estoque | Keys com `sold_at` nulo |
| Investido | `SUM(individual_cost)` do estoque |
| Receita simulada | `SUM(simulated_income)` — estimativa de receita se tudo vender |
| Listadas | Keys com `listed_at` preenchido e `sold_at` nulo |
| Não listadas | Keys com `listed_at` nulo e `sold_at` nulo |
| Expirando em 30 dias | Keys com `expires_at` entre hoje e hoje+30 dias |

### Gráfico — Evolução dos últimos 12 meses

Barras agrupadas por mês de `sold_at`:
- **Receita** — `SUM(sold_price)` por mês
- **Lucro** — `SUM(sale_profit)` por mês

### Tabela de jogos vendidos

Lista todas as keys vendidas no período (ordenadas por maior lucro), com totais na primeira linha. O total de receita e lucro deve bater com os cards de KPI.

## Fechamento mensal

Livro-caixa dos dois sócios, em **R$**, acessível em `/financial-months`. Domínio distinto da aba Financeiro acima — aquela é análise de vendas em €, esta é o caixa da empresa; compartilham só o prefixo do nome.

O mês é montado **lançamento a lançamento**: nada é distribuído automaticamente. "Fechar" apenas encerra o mês e abre o próximo. Ver [ADR 0005](adr/0005-financial-month-records-instead-of-calculating.md).

### As quatro contas

Nenhum saldo é persistido — o saldo é sempre a soma dos movimentos do mês. Saldo da empresa = soma das quatro contas.

| Conta | Papel |
|---|---|
| **Principal** | Caixa operacional. Entra faturamento; saem verba de TF2, despesas, transferências e saques |
| **TF2** | Verba do mês para comprar TF2 keys. Recebe a alocação do Principal; as compras debitam daqui |
| **Reinvestimento** | Caixinha. Débito exige justificativa |
| **Emergência** | Caixinha. Débito exige justificativa |

Saldo negativo é **permitido** e aparece em vermelho — comprar acima da meta é decisão de negócio legítima, e as contas são baldes internos, não contas bancárias.

### Roteiro do mês

Roteiro, não invariante: nada é bloqueado se você pular ou trocar a ordem.

| # | Ação | Efeito | Categoria |
|---|---|---|---|
| 1 | Registrar o saque da Gamivo | crédito na conta escolhida | `income` |
| 2 | Definir a verba de TF2 (qtd × preço) | débito no Principal + crédito no TF2 | `tf2_allocation` |
| 3 | Lançar gastos (impostos, Claude…) | débito na conta escolhida | `expense` |
| 4 | Abastecer o Reinvestimento | débito Principal + crédito Reinvestimento | `transfer` |
| 5 | Abastecer a Emergência | débito Principal + crédito Emergência | `transfer` |
| 6 | Sacar para os sócios | dois débitos na conta escolhida, um por sócio | `partner_distribution` |
| 7 | Comprar TF2 ao longo do mês (qtd × preço) | débito no TF2 | `tf2_purchase` |
| 8 | Fechar o mês | devolve a sobra do TF2 e abre o próximo | `transfer` (gerado) |

**A ordem importa por um motivo só:** quem usa porcentagem (passos 4, 5, 6) a aplica sobre o **saldo atual da conta de origem**. Seguindo o roteiro, o Principal já está pós-TF2 e pós-gastos quando os 20% incidem — o que reproduz naturalmente a antiga cascata automática, sem o sistema rastrear degrau nenhum.

### Regras

- **Verba de TF2 é conta real, não reserva virtual.** O dinheiro sai do Principal de verdade.
- **A meta mora no movimento.** Definir a meta *é* lançar um `tf2_allocation` com `quantity` × `unit_price` — não há coluna de meta que possa divergir do dinheiro movido. Um segundo lançamento complementa o orçamento no meio do mês. **Não existe incremento automático.**
- **Transferência é genérica.** Uma categoria `transfer` para qualquer par de contas — o par já declara a intenção. Sempre dupla partida.
- **Justificativa protege as caixinhas.** `description` é obrigatória em qualquer movimento que **debite** Reinvestimento ou Emergência.
- **Distribuição:** uma ação (valor + conta + % do Sócio 1) gera **dois** débitos; o Sócio 2 leva o resto exato, então a soma reconcilia e o **centavo órfão fica com o Sócio 1**. Sócios são identificados por posição (`partner_slot` 1 ou 2) — nomes não são guardados.
- **Fechar não distribui.** Gera **um único** lançamento: um `transfer` TF2→Principal com a sobra da verba. O TF2 fecha em zero e toda conta abre o mês seguinte com o próprio saldo. Se a verba foi estourada (TF2 negativo), a devolução troca de origem e vira débito no Principal.
- **Ciclo:** no máximo um `draft`. O bootstrap é o único caminho manual de criação e recusa rodar se qualquer mês existir; do segundo mês em diante o draft nasce só do fechamento.
- **Carry-forward:** o novo draft herda as 3 porcentagens (só como **prefill de formulário**, nunca aplicadas sozinhas) e os saldos, como movimentos `opening`.
- **Reabrir** só o fechamento mais recente: apaga o draft seguinte, desfaz os movimentos gerados e volta o status.

### Correção de erro

Não há edição — só exclusão, e **por lançamento inteiro**. Um lançamento pode virar mais de uma linha (a transferência grava débito *e* crédito), e as linhas irmãs compartilham `group_id` para sumirem juntas. Apagar só uma delas criaria ou destruiria dinheiro.

Não são apagáveis:

| O quê | Por quê |
|---|---|
| Qualquer linha de mês `closed` | Fechado é histórico, e seus saldos já abriram o mês seguinte |
| Movimento gerado (`is_generated`) | Quem o desfaz é o `reopen`, em bloco |
| Saldo de abertura (`opening`) | Carrega o mês anterior; sumiria sem nada o repor |

## Bundles

### Definição

Bundles são pacotes de jogos lançados por diferentes lojas parceiras ou marketplaces, como:

- Humble Bundle
- Fanatical
- Green Man Gaming

Esses bundles são lançados com frequência, inclusive diariamente, e o sistema centraliza essas informações na aba "Bundles" para consulta e gestão.

### Informações armazenadas por bundle

Cada bundle pode conter, entre outros, os seguintes dados:

- nome do bundle;
- data de lançamento;
- descrição;
- preço em dólar;
- preço mínimo estimado em TF2;
- lista de jogos incluídos;
- região / region lock;
- data de expiração;
- identificadores externos, quando disponíveis.

### Fonte das informações

As informações dos bundles são obtidas por integrações externas, principalmente:

- API GG.deals, utilizada pelo sistema para coletar dados básicos dos bundles(jogos do bundle, preço, data de lançamento, etc).
- SteamGifts(sem API, conferimos manualmente), para dados como:
  - region lock;
  - data de expiração;
  - jogos contidos no bundle;

### Choices

Choices são tratados como uma categoria específica de bundle.

#### Regras de negócio dos choices

- Choices correspondem, principalmente, aos pacotes mensais da Humble Bundle.
- Diferentemente de bundles comuns, os choices podem ser comprados e vendidos de forma mais imediata.
- O sistema exibe os choices na mesma área de bundles, mas eles possuem comportamento comercial específico.
- O fato de um jogo estar ou ter estado em choice impacta sua decisão de venda, pois isso pode alterar seu valor de mercado ao longo do tempo.

---

## Regras de análise de venda de jogos de bundle

A decisão de vender ou não um jogo é baseada em critérios combinados.

### Fatores analisados

O sistema considera, entre outros, os seguintes fatores:

- tempo desde o lançamento do bundle;
- preço atual do jogo;
- popularidade do jogo;
- histórico de participação em bundle;
- histórico de participação em choice;
- percentual de valorização ou desvalorização do jogo em relação ao preço pago.

### Lógica geral

Existe uma regra de venda no sistema que avalia os dados recebidos de API para determinar se um jogo deve ou não ser colocado à venda.

Essa análise considera que:

- jogos recém-bundleados podem sofrer desvalorização temporária;
- jogos que saem de bundle ou choice podem voltar a valorizar;
- jogos com maior popularidade tendem a ter comportamento comercial mais previsível;
- o preço atual precisa ser comparado com o valor pago na compra para validar a margem de lucro real.

### Objetivo da regra

O objetivo da regra é evitar vendas precipitadas e aumentar a chance de venda com lucro saudável, respeitando o comportamento do mercado após bundles e choices.

---

## Fluxo de Compra

### Objetivo

O fluxo de compra tem como objetivo identificar oportunidades de aquisição de jogos com margem suficiente para revenda futura.

### Origem dos clientes

Os clientes podem ser encontrados de duas formas principais:

- por meio da SteamTrades;
- por meio de contatos já existentes salvos na Steam.

### Etapas do fluxo de compra

#### 1. Identificação do inventário do cliente

Ao acessar o perfil do cliente na SteamTrades, são capturados os jogos disponíveis na seção *“I have”*.

#### 2. Geração de arquivo de entrada

Os jogos identificados são organizados em um arquivo .txt.

Esse arquivo é utilizado como entrada para a API interna chamada *Price Researcher*.

#### 3. Pesquisa de preços

A API *Price Researcher* consulta os jogos informados e retorna um arquivo .txt com os respectivos preços pesquisados.

#### 4. Cálculo da oferta

O retorno é importado para uma planilha Excel responsável por calcular o valor da oferta ao cliente.

Essa planilha aplica as seguintes regras:

- desconta as taxas do marketplace utilizado como referência, principalmente a *Gamivo*;
- calcula o valor de compra com base na margem desejada;
- por padrão, divide o valor líquido por *2, visando uma margem equivalente a **100% de lucro* sobre o custo;
- em alguns casos, dependendo do cliente, pode ser utilizada uma divisão por *1,8* ou *1,7*, reduzindo a margem-alvo para tornar a proposta mais competitiva.

### Regra de negociação

Após o cálculo da oferta base, a negociação é feita manualmente com o cliente na Steam.

O operador busca o melhor equilíbrio entre:

- conseguir fechar a compra;
- manter o maior lucro possível;
- evitar compras com margem insuficiente para revenda.

### Observação importante

Quanto menor for a margem obtida na compra, menor tende a ser a margem potencial na venda futura.

Isso ocorre porque:

- os jogos podem desvalorizar;
- o mercado pode sofrer impacto por novos bundles;
- o preço de revenda pode cair antes da venda ser concluída.

---

## Fluxo de Venda

### Objetivo

O fluxo de venda tem como objetivo identificar automaticamente quando um jogo comprado já possui condições favoráveis para ser anunciado.

### Fonte da decisão

O sistema, com sua própria lógica interna (`UpdateOffersUseCase` + `ComparisonAlgorithm`), consegue:

- consultar o preço atual do jogo;
- comparar o preço atual com o valor pago na compra;
- verificar se o jogo está ou esteve em bundle;
- verificar se o jogo está ou esteve em choice;
- apoiar a análise do momento ideal de venda.

### Regras consideradas

A análise de venda considera que:

- um jogo em bundle pode estar temporariamente desvalorizado;
- um jogo após sair de bundle ou choice pode recuperar valor;
- o preço atual precisa ser suficiente para garantir a margem esperada;
- nem todo jogo comprado deve ser colocado à venda imediatamente.

### Periodicidade

A verificação é diária: o `RegulateMinApiUseCase` recalcula o `min_api` de todas as keys não vendidas todo dia às 07:30, e o auto-sell roda em seguida. A reprecificação das ofertas já ativas roda a cada 5 minutos (quando somos os mais baratos) ou de hora em hora (quando não somos).

### Automação

Com base nessa análise diária, os jogos elegíveis são colocados à venda automaticamente.

## Regras Operacionais Importantes

### Sobre margem de compra

- A margem padrão de compra é calculada para dobrar o valor investido(100% de lucro).
- Em negociações específicas, é permitido reduzir essa margem-alvo para aumentar a chance de fechamento.

### Sobre risco de mercado

- O valor dos jogos pode cair após a compra.
- A entrada de um jogo em novo bundle ou choice pode impactar negativamente o preço.
- Por isso, a margem de compra precisa considerar risco de desvalorização.

### Sobre bundles e choices

- Jogos que fazem parte de bundles ou choices precisam de tratamento especial.
- O simples preço atual não é suficiente para decidir a venda.
- O contexto do jogo no mercado precisa ser avaliado junto com sua origem.

### Sobre automação

- A automação auxilia a operação, mas a lógica depende da qualidade das integrações e dos parâmetros configurados.
- As decisões automáticas de venda dependem da atualização correta de preços, status de bundle e status de choice.

---

## Resumo das Regras de Negócio

### Compra

O sistema apoia a compra a partir da lista de jogos do cliente, pesquisa preços por API, calcula oferta líquida com base em taxas e aplica uma margem-alvo para garantir lucro futuro.

### Venda

O sistema avalia diariamente os jogos comprados, comparando preço atual, histórico de bundle/choice, popularidade e tempo de mercado para decidir se o jogo já deve ser colocado à venda.

### Bundles e choices

Bundles e choices impactam diretamente o valor de mercado dos jogos e, por isso, são elementos centrais da regra de negócio.