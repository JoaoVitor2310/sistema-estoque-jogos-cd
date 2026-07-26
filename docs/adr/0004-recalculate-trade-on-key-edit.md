# Editar uma key recalcula o custo e os lucros do lote inteiro

O `individual_cost` de uma key é um rateio do custo total da trade (em TF2) proporcional ao income de cada key, logo depende do somatório de incomes de **todas** as keys do lote. Editar o `market_price` de uma key muda esse somatório e, portanto, o custo e os lucros de compra de todas as keys da mesma trade — então, **quando o `market_price` muda**, o `UpdateKeyUseCase` recalcula o lote inteiro, reusando a mesma lógica do `RegisterKeyUseCase`. O `market_price` é o único campo editável que alimenta as fórmulas (`tf2_quantity` é da trade, não editável na key); qualquer outra edição persiste só os campos alterados, sem recalcular.

Isto **reverte** a regra anterior ("`individual_cost` é imutável após registro; o `UpdateKeyUseCase` nunca o recalcula"). Aquela regra deixava um `market_price` digitado errado sem conserto a não ser apagando e reimportando a trade inteira, e produzia uma `purchase_profit_percent` inconsistente com o próprio `simulated_income` recalculado.

## O lote é identificado por uma FK `trade_id` em `keys`

As keys ganham uma coluna `trade_id` (nullable, FK → `trades.id`, `nullOnDelete`). O fluxo `POST /trades/{trade}/import` (`TradeController::importKeys`) passa a `Trade` ao `RegisterKeyUseCase`, que grava o vínculo. O recálculo agrupa as keys por `trade_id`.

A importação é **atômica**: ou o lote inteiro entra, ou nada entra. Isso é o que garante que um `trade_id` nunca aponte para um lote meio-importado — o rateio de `individual_cost` depende do somatório de incomes de **todas** as keys do lote, então um lote parcial produziria um rateio errado desde o registro.

## Considered Options

- **Recalcular só a key editada, mantendo `individual_cost` congelado** — corrige a inconsistência interna da key, mas o custo do lote continua errado. Rejeitado: não resolve o problema real.
- **Agrupar por `(total_paid, acquired_at)`** — chave composta que o `FinancialService` já usa. Não exige migration, mas é frágil (duas trades com o mesmo rótulo e data colidiriam) e não usa a tabela `trades` que já existe. Rejeitado em favor da FK.
- **FK `trade_id` em `keys`** — escolhido. Vínculo explícito e robusto, aproveitando a tabela `trades`. Custo: migration + popular o `trade_id` no fluxo de import.

## Consequences

- **A trade não é excluída após importar.** Como as keys referenciam `trades.id` e a FK é `nullOnDelete`, apagar a trade zeraria o `trade_id` das keys — anulando o vínculo. Por isso importar as keys (sem erros) marca `trades.is_imported = true` em vez de excluir; a aba de Trades, no seu default (view Abertas), esconde importadas — o card sai da tela mas permanece no banco, e as views Importadas / Todas seguem exibindo (colapsado, expandível para edição). Ver o campo `is_imported` em `CLAUDE.md`.
- **A trade passou a ser obrigatória para registrar uma key.** `RegisterKeyUseCase::execute(Trade $trade, array $games)` exige a trade na assinatura, e `POST /trades/{trade}/import` é o único caminho de entrada — o cadastro avulso (`POST /keys`) e a importação XLSX foram removidos. Logo, toda key nova tem `trade_id`. A coluna segue nullable apenas por causa das keys anteriores a esta mudança; para uma dessas, o `UpdateKeyUseCase` recalcula **apenas ela** (não há lote identificável).
- O recálculo alcança **todas** as keys do lote, inclusive já vendidas — corrige retroativamente o `sale_profit` de uma key vendida cujo custo estava errado. Desejado, mas é um efeito colateral a ter em mente.
- **`min_api`/`max_api` não são recalculados** por esta operação. O `min_api` se auto-corrige no `RegulateMinApiUseCase` diário; recalcular `max_api` fica de fora de propósito, para não quebrar a trava de `max_api` que o `AutoSellUseCase` aplica em keys velhas já listadas.
