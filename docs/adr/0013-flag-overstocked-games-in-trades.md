# Jogo encalhado sai da oferta, mas continua registrado na trade

**Status:** accepted — implementado em 2026-09-16.

Continuávamos comprando jogos de que já havia várias keys paradas. Na análise do dump de produção de 2026-09-13, **312 das 1.070 keys em estoque** (~29%) estavam em jogos que não escoam, e cerca de **1 em cada 10 linhas** das trades abertas dos 60 dias anteriores era de um desses jogos.

A decisão tem duas metades: o jogo encalhado **não é ofertado** ao fornecedor, e **continua virando linha** da trade, marcada em `trade_lines.is_overstocked`. Na prospecção, `profitable` — que é literalmente o texto do comentário que o `price_researcher` posta na lista do SteamTrades — sai sem ele; a linha da trade fica, para a equipe ver o que apareceu na lista e não foi ofertado. Um jogo está encalhado quando as três condições valem juntas (`App\Domain\Trades\OverstockPolicy`):

| Condição | Constante | Valor |
|---|---|---|
| Keys paradas em estoque | `MIN_STOCK` | 3 |
| Idade da key parada mais antiga | `MIN_AGE_DAYS` | 120 dias ou mais |
| Tempo para o estoque escoar no ritmo do próprio jogo — ou nenhuma venda na janela | `MAX_COVERAGE_DAYS` / `SALES_WINDOW_DAYS` | mais de 120 dias / 90 dias |

Cada condição corrige um erro que as outras cometeriam sozinhas. **Quantidade sozinha não decide**: há jogo com seis keys que vende toda semana. **Idade sozinha também não**: a mediana de dias até vender é 24 e a média 47, então um mês parado ainda é venda normal. O que condena é o **tempo de escoamento**: estoque dividido pelo ritmo de venda dos últimos 90 dias.

### Por que 120 dias de idade

Nem os 30 cogitados no começo, nem os 60 da primeira versão: a compra de bundle é feita **em quantidade** e espera cerca de **3 meses** até vender, enquanto o preço se recupera do lançamento. Qualquer limiar dentro dessa espera acusaria como encalhe o lote que está parado de propósito — e contar a idade a partir de `listed_at` em vez de `acquired_at` foi rejeitado pelo negócio: a regra é sobre **quanto já temos**, não sobre key anunciada que não vende.

Os 120 dias cobrem a espera com um mês de folga e custam pouco. No dump de produção:

| `MIN_AGE_DAYS` | Grupos jogo+região sinalizados | Keys |
|---|---|---|
| 60 | 57 | 308 |
| 90 | 45 | 219 |
| **120** | **43** | **205** |
| 150 | 35 | 174 |

De 120 para 90 a lista cresce só em 2 grupos; de 90 para 60, em 12 — justamente os lotes ainda dentro da janela de valorização. O preço da escolha é a demora: a partir da primeira compra o aviso só aparece quatro meses depois, e nesse intervalo o mesmo jogo pode ser comprado de novo sem sinal nenhum. Como a mediana até vender é de 24 dias, quem chega aos 120 com 3 ou mais keys paradas é caso real de acúmulo.

Uma alternativa mais precisa ficou registrada para depois: manter um limiar curto e **ignorar as keys ainda dentro da janela de valorização do bundle**, usando a data de lançamento que o sistema já conhece (a mesma de `KeyEligibility::BUNDLE_EXCLUSION_DAYS`). Assim o jogo que não veio de bundle seria pego mais cedo. Depende de o vínculo jogo↔bundle estar preenchido, e por isso não entrou agora.

## Considered Options

- **Fora da oferta, dentro da trade** — escolhido. Não compramos mais do que já está parado, e o que foi deixado de fora continua visível na aba.
- **Cortar o jogo da oferta e da trade** — implementado primeiro e revertido: o jogo sumia sem deixar rastro, e a única pista era um modal à parte. Também criava um caso próprio — lista inteira encalhada virava trade vazia, com link de entrega para um formulário sem linhas.
- **Só marcar, seguir ofertando** — versão intermediária, usada enquanto se decidia. Servia para observar a regra, mas continuava comprando o que ela apontava.
- **Mandar a marca por jogo e deixar o `price_researcher` filtrar** — rejeitado. `profitable` já significa "o que ofertar", e o serviço monta o comentário e o total a partir dele; espalhar a regra por dois repositórios exigiria recalcular o total do outro lado para ele continuar batendo com as linhas listadas.
- **Recalcular a marca toda vez que a trade é aberta** — rejeitado. Refletiria o estoque de hoje, e uma trade antiga deixaria de mostrar o que a regra viu quando ela nasceu — exatamente o que se quer observar.
- **Identidade só pelo nome, somando todas as regiões** — rejeitado. Estoque parado de uma região não vira venda em outra: somar EU com ROW deixa um EU que vende esconder um ROW encalhado, ou um ROW encalhado condenar um EU que vende.
- **Só quantidade e idade ("3 keys há mais de 1 mês")** — rejeitado. Era a formulação inicial do negócio. Pegaria 83 jogos, entre eles os que vendem no ritmo do estoque.
- **Teto de cópias do mesmo jogo por trade** e **checar giveaway antes de comprar** — adiados, registrados em [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md).

## Consequences

- **A identidade do jogo é nome normalizado + região** (`GameStock::identityOf`). O nome passa pelo `GameNameNormalizer` — "Alien Shooter 2: Reloaded" e "Alien Shooter 2 Reloaded" são o mesmo produto. Região **nula é a global**, que não se escreve na key, e forma o próprio grupo; a região é comparada sem caixa e sem espaço nas pontas (`LATAM` = `latam`). Sinônimos como `US`/`USA`/`NA` **não** são unidos — isso é canonizar regiões, pendência própria.
- **Lista inteiramente encalhada não vira trade.** Sem nada a ofertar não há comentário, e a trade da prospecção só nasce quando se comenta (`CommentPolicy::shouldComment` recebe o subconjunto ofertado). Essa lista aparece só no botão "Jogos encalhados" da aba.
- **A trade contém jogo que não foi ofertado.** É o preço de registrar a lista inteira: a linha marcada como `Encalhado` está lá, e nada impede a equipe de preencher a key dela — o que é justamente o caminho para comprar mesmo assim, quando a decisão humana for essa.
- **O `price_researcher` não mudou, e não sabe do encalhe.** Ele continua postando `profitable` com o `total_tf2_price` que recebe; ambos já vêm sem os encalhados. Devolver também os nomes pulados foi cogitado, para o log do serviço, e descartado: o que ofertar é decisão nossa, e campo que ninguém consome é contrato a manter à toa.
- **A agregação de estoque voltou a rodar por perfil avaliado com jogo lucrativo**, e não só quando a trade é criada: a marca agora decide o que é ofertado, então precisa existir antes do `should_comment`.
- **A marca é gravada, o estado do estoque não.** O que se grava é o que a regra viu no momento da escrita da linha; o estoque em si é agregado a cada consulta, e a lista "Jogos encalhados" da aba sempre reflete o de hoje. Por isso uma linha marcada pode não aparecer mais na lista — o jogo escoou depois.
- **A marca é refeita quando o nome ou a região da linha mudam**, pelo mesmo critério que apaga o `gamivo_id` (`LineGamePair::changedBy`), e pelas duas autoridades: o supplier que corrige a região na entrega muda o grupo de estoque da key. Qualquer outra edição preserva a marca. Linhas anteriores à coluna nasceram `false`, sem backfill — recalculá-las gravaria um fato que nunca aconteceu.
- **Vale para qualquer canal de compra** — trade com fornecedor, compra direta na loja do bundle ou compra na Gamivo. Restringir à trade com fornecedor foi cogitado ("na pesquisa de bundle a compra é do pacote inteiro") e rejeitado: todo canal repõe estoque, e jogo encalhado comprado de bundle ou na Gamivo encalha do mesmo jeito. A marca também não depende do canal escolhido depois — o canal pode mudar no cabeçalho sem refazer as linhas.
- **O supplier nunca vê a marca.** A projeção da entrega (`DeliveryReadModel::LINE_COLUMNS`) seleciona as colunas à mão, e saber que o jogo está encalhado do nosso lado ancoraria a negociação para baixo.
- **Uma agregação sobre `keys` por escrita que precisa da marca.** Na aba, a cada gravação que troca o nome ou a região — o autosave faz isso a cada pausa de digitação no nome. A varredura é da tabela inteira (`Seq Scan`, ~1,5 ms com 5.292 keys); índice ou cache estão em [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md).
- **Estoque sem data de aquisição não é sinalizado.** `acquired_at` cai para `created_at`; sem nenhum dos dois, a regra não consegue provar a idade.
- **Keys nunca listadas contam como estoque parado.** Jogo sem `gamivo_id` nunca chegou a ser anunciado, e a regra o trata como se não tivesse procura. Ver [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md).
