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

Cada key terá os seus valores individuais, mas o jogo é um valor que pode ser repetido, no exemplo acima os dois tem o mesmo gamivo_id por exemplo. A ideia é que futuramente no sistema as keys tenham um relacionamento com os jogos para não repetir esses dados.


### Data de Expiração
Data que a key para de funcionar. Quando falta 30 dias, é enviado um email para carcadeals@gmail.com para nos alertar e tomar uma ação. A ideia futuramente é colocar a venda automaticamente quando faltar 30 dias, tendo no mínimo 0,02 de lucro e no dia que expira, remover da Gamivo e avisar por email.

### Region lock
Determina a região que um jogo pode ser ativado, exemplo:
Jogo: Deceive Inc. - Region Lock: EU/NA
Significa que esse jogo só pode ser ativado por usuários que estão nessas regiões(fisicamente ou por VPN). A regionalidade influencia o valor final do jogo.


## Trades
Trades é uma compra realizada com nossos fornecedores. Nessa troca pode ter diversos jogos, cada jogo é calculado individualmente e no final é enviado o somatório dos valores dos jogos, resultando no valor da trade. Cada trade é inserida de uma vez no sistema para facilitar, e o Valor Pago Total é um reflexo disso, exemplo:
5.5x TF2 Keys / 8

Significa que foi gasto 5,5 TF2 keys para um trade de 8 jogos. Esses 8 jogos serão enviados de uma única vez, e o valorPagoIndividual vai conseguir calcular o preço de cada jogo.

## Lucro esperado
O lucro inicial considerado é sempre de 100% quando é analisado um jogo para ser comprado, porém isso pode mudar para os seguintes casos:
- Fornecedores de longa data
- Jogos muito caros
- Oportunidade de conhecer novos fornecedores

A ideia é não deixar ficar abaixo de 30%.

## Planilhas de cálculo
Atuamente os preços que iremos ofertar para os fornecedores são calculados em planilhas de acordo com o lucro acima, mas a ideia é futuramente criar uma "calculadora de ofertas" para automatizar esse processo.

## Custo benefício
Como cada key tem um longo processo a ser feito, o ideal é não comprar jogos abaixo de 1 euro(preco cliente), por não compensar o trabalho e tempo dedicado à aquela key. Isso não é uma regra imutável, mas precisamos definir melhor quando vale a pena ou não comprar.

## Giveaway
Jogos que são oferecidos de maneira gratuita por sites. O preço desses jogos despenca no mercado, mas isso demora umas horas ou dias para acontecer. Se comprarmos um jogo no meio tempo dele sair no giveaway e não sabermos, é quase certo que iremos ter prejuízo.
### https://www.gamerpower.com/api-read - Já descobrimos essa API para saber os jogos lançados em Giveaway.

## Como saber quanto vale o jogo agora
Atualmente só vendemos keys na Gamivo e lá que é verificado o preço atual. O preço é definido com base no valor que os nossos concorrentes estão vendendo, sendo sempre o preço do menor - 0,01 para ficar o mais visível e atraente para os clientes. Esse sistema não tem essa lógica atualmente, mas a nossa API Gamivo faz esses cálculos.

## Tempo de venda da key
Para os jogos já comprados, consideramos um alvo de 80% de lucro para colocar o jogo a venda, mas com o tempo esse número vai caindo:
Tempo(meses) - Lucro % para colocar a venda
- - 80%
7 - 70%
10 - 60%

Essa lógica também está presente na API Gamivo.

## Taxas com problemas em key
Quando uma key tem problema, a Gamivo nos dá a opção de reembolsar o cliente ou fornecer outra chave. Se tivermos outra key, enviaremos para o cliente. Se não tivermos a key, a chave é reembolsada e o dinheiro que ganhamos com essa venda é devolvido para o cliente. Atualmente a Gamivo tem 2 taxas de punição para quando tem problemas com as keys:
1 euro - Aplicada quando uma chave tem problema de region_lock, ou chave duplicada e etc. Mais comum de acontecer por conta de algum descuido dos nossos fornecedores, normalmente os fornecedores reembolsam o preço que pagamos da key e esse 1 euro de taxa.
10 euros - Aplicada quando uma chave foi revogada, ou seja, vendemos para um cliente na Gamivo mas os desenvolvedores revogaram essa chave. Menos comum de acontecer, normalmente acontece quando caímos em golpe e não somos reembolsados da key nem dessa taxa, é muito prejuízo e esse cenário deve ser evitado ao máximo.


## Financeiro
Futuramente queremos ter uma aba somente de análise financeira do CarcaDeals, para vermos médio de lucro do mês, quanto foi gasto, quanto de retorno e dados relevantes para termos uma visão do negócio.

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

O sistema utiliza uma API própria para:

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

A verificação é realizada semanalmente.

### Automação

Com base nessa análise semanal, os jogos elegíveis são colocados à venda automaticamente.

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

O sistema avalia semanalmente os jogos comprados, comparando preço atual, histórico de bundle/choice, popularidade e tempo de mercado para decidir se o jogo já deve ser colocado à venda.

### Bundles e choices

Bundles e choices impactam diretamente o valor de mercado dos jogos e, por isso, são elementos centrais da regra de negócio.