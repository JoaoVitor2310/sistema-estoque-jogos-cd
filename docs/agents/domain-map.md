# Mapa de domínios

Referência estrutural rápida — modelo, tabela, campos relevantes. Para entidades, relacionamentos e ciclo de vida em tabelas, veja primeiro [`docs/wiki/DOMAIN.md`](../wiki/DOMAIN.md); para regras de negócio e fórmulas por trás de cada campo, [`docs/PRODUCT.md`](../PRODUCT.md) e [`docs/GAMIVO.md`](../GAMIVO.md) são a fonte única — não repita a regra aqui, só aponte para ela.

## 1. Keys (`Key` → tabela `keys`)

Modelo central. Representa keys compradas e/ou vendidas.

Campos relevantes:
- `claim_type` — enum do tipo de problema que ocorreu na key
- `steam_id` — ID na Steam
- `game_name`, `region` — nome do jogo e região de bloqueio (ex: EU)
- `individual_cost` — custo individual da key
- `tf2_quantity` — quantidade de TF2 keys pagas pela trade
- `market_price` — preço no marketplace na data de compra
- `simulated_income` — receita líquida após taxas Gamivo
- `purchase_profit`, `purchase_profit_percent` — lucro na compra
- `sold_price`, `sale_profit`, `sale_profit_percent` — dados da venda
- `gamivo_id` — ID externo no marketplace Gamivo
- `key_code` — código da key entregue ao cliente
- `acquired_at`, `listed_at`, `sold_at`, `expires_at` — datas do ciclo de vida
- `supplier_url` — URL do perfil do fornecedor
- `trade_id` — FK → `trades.id` (nullable): a trade/lote de onde a key veio; populado só no import por trade. Usado para recalcular o rateio de custo ao editar (ver [`docs/adr/0004`](../adr/0004-recalculate-trade-on-key-edit.md))
- `min_api`, `max_api` — limites de preço aceitos pela API Gamivo

Classes-chave: `KeyCalculationService` (fórmulas), `RegisterKeyUseCase` (único caminho de entrada — sempre via `POST /trades/{trade}/import`), `UpdateKeyUseCase` (edição inline). Fluxo completo e agendamentos: [`docs/wiki/AUTOMATIONS.md`](../wiki/AUTOMATIONS.md).

**Toda key nasce de uma trade.** Não existe cadastro avulso (`POST /keys`) nem importação XLSX; `keys.trade_id` é nullable só por causa de keys anteriores a esse vínculo. **A importação é atômica**: `RegisterKeyUseCase` roda o lote inteiro numa transação — cada key roda num savepoint próprio para reportar todos os erros de uma vez, mas se qualquer uma falhar, nada é persistido e a trade não é marcada como importada. `201` quando o lote inteiro entra, `422` quando nada entra — não existe `207 Multi-Status`. Ver [`docs/adr/0004`](../adr/0004-recalculate-trade-on-key-edit.md).

**Editar `market_price` recalcula o lote inteiro.** É o único campo editável que dispara recálculo de `individual_cost`/lucros de todas as keys da mesma trade (via `trade_id`) — outras edições persistem só os campos alterados. Ver [`docs/adr/0004`](../adr/0004-recalculate-trade-on-key-edit.md).

## 2. Cálculo de lucro (`KeyCalculationService` + `Domain/Pricing`)

Tiers de taxa, fórmulas de `simulated_income`, `min_api`/`max_api`: ver [`docs/GAMIVO.md`](../GAMIVO.md#algoritmos-de-precificação) e a fonte oficial [`docs/GAMIVO_Merchant-pricing.pdf`](../GAMIVO_Merchant-pricing.pdf). Não duplicar a tabela de taxas aqui — ela já teve drift uma vez entre este arquivo e o PDF oficial.

## 3. Bundles

Agrupamento de jogos (`bundle` ou `choice`). Many-to-many com `Game` via `bundle_games`. Regra da janela de exclusão de 21 dias (`KeyEligibility::BUNDLE_EXCLUSION_DAYS`): ver [`docs/wiki/DOMAIN.md#bundle-vs-choice`](../wiki/DOMAIN.md#bundle-vs-choice) e [`docs/GAMIVO.md`](../GAMIVO.md).

## 4. Assets (`Asset` → tabela `assets`)

Representa ativos de troca (ex: TF2 key). Campos: `price_euro`, `price_dollar`, `price_brl`. Usado por `KeyCalculationService` para converter o custo da trade em euros.

## 5. Fees (`Fee` → tabela `fees`)

Taxas do marketplace. Campos: `name`, `preco`. Chaves usadas: `gamivoPercentualMenor`, `gamivoFixoMenor`, `gamivoPercentualMaior`, `gamivoFixoMaior`.

## 6. Suppliers e Trades (`Supplier`/`Trade`/`TradeLine` → tabelas `suppliers`/`trades`/`trade_lines`)

- `Supplier` — fornecedor Steam. Campos: `steam_id`, `url`, `region`, `initial_offer_pct`, `is_added` (marcado manualmente como adicionado à lista de trade), `has_traded`, `category` (enum `SupplierCategory`: `vip` | `blocked`)
- `Trade` — registro de uma lista de jogos comentada/ofertada a um supplier. Campos: `supplier_id`, `list_code`, `last_commented_at`, `title`, `date`, `message_sent`, `is_imported`, `tf2_qty`. `Trade hasMany Key` via `keys.trade_id` — as keys efetivamente compradas daquele lote (populado no `POST /trades/{trade}/import`)
  - `is_imported` — importar as keys da trade (sem erros) marca `is_imported = true`. A trade importada permanece no banco (o vínculo `keys.trade_id` continua válido — não excluir a trade após importar). A aba de Trades usa `TradeService::paginate(filters, sort, dir, perPage)` com o filtro `view` (`open`/`imported`/`all`); default `open` esconde importadas. Ver [`docs/adr/0004`](../adr/0004-recalculate-trade-on-key-edit.md)
  - **Reimportação:** `POST /trades/{trade}/import` não é bloqueado por `is_imported = true` — o botão "Importar keys" fica disponível mesmo em trades já importadas (com aviso de confirmação e cor de alerta no frontend), rodando `RegisterKeyUseCase` de novo. Não há exclusão automática das keys antigas: é responsabilidade do usuário apagá-las antes de reimportar, para não duplicar estoque (`KeyRepository::findByKeyCode` apenas marca `is_duplicate = true` em colisão de `key_code`, não bloqueia). `is_imported` continua sendo um boolean simples, sem timestamp/contador de reimportações.
- `TradeLine` — uma **linha da trade** (ver [`CONTEXT.md`](../../CONTEXT.md)): um jogo dentro da trade. Campos: `trade_id` (FK, `cascadeOnDelete` — a linha não existe fora da trade), `position` (ordem de exibição), `game_name`, `market_price` (decimal), `popularity` (int), `region`, `bundle`, `expires_at` (date), `key_code`, `gamivo_id`. Todos nuláveis menos a FK e a posição: linha em branco é estado normal de trade. `Trade hasMany TradeLine` via `lines()`, já ordenado por `position`
  - **Escrita:** `TradeLineController` (recurso próprio, aninhado sob a trade), atrás do mesmo `CheckPermission` — `POST /trades/{trade}/lines`, `PATCH /trades/{trade}/lines/{line}`, `DELETE /trades/{trade}/lines/{line}`, o grupo inteiro com `scopeBindings` para a linha de uma trade não ser alcançável pela URL de outra. Entrada tipada por `App\UseCases\Trades\DTO\TradeLineDTO`, montado no `TradeLineRequest::toDTO()`. O `PATCH` é **patch parcial**: o DTO carrega quais colunas o payload trouxe, então mandar vazio limpa e não mandar não toca — é o caminho único de escrita de linha, e o ponto em que a entrega pelo supplier entra depois com um payload menor (ver [`docs/adr/0008`](../adr/0008-supplier-fills-trade-through-tokenised-link.md)). `PUT /trades/{trade}` grava só os campos da própria trade
  - **`position`** é mantida contígua (0..n-1): inserir numa posição empurra as seguintes, remover fecha o buraco — ambos em transação. É o que permite duplicar uma linha "logo abaixo da original"
  - **Normalização** por `App\Domain\Trades\TradeLineValue`, compartilhada com o backfill: string vazia vira `null`, e o que não couber na coluna tipada (preço não numérico, data impossível) vira `null` em vez de recusar a gravação — o autosave da aba dispara a cada tecla, e recusar "02/0" transformaria digitação em erro
  - **Import:** `POST /trades/{trade}/import` **não recebe corpo** — o lote sai das linhas gravadas. `RegisterKeyUseCase::execute(Trade)` lê a linha (`game_name`, `market_price`, `region`, `expires_at`, `key_code`, `gamivo_id`) e a trade (`date` → `keys.acquired_at`, `tf2_qty` → `keys.tf2_quantity`, `supplier_id`/`supplier.url` → `keys.supplier_id`/`supplier_url`); o resto da key vem de `KeyDefaults`. Linha em branco não vira key. Antes o navegador mandava o array inteiro, inclusive o `market_price` que define o rateio de `individual_cost` do lote
  - **Prontidão para importar** em `App\Domain\Trades\ImportReadinessPolicy`, com os impedimentos tipados em `App\Domain\Enums\TradeImportBlocker`: linha preenchida (com nome **ou** preço) exige nome, preço > 0 e `key_code`; a trade exige `tf2_qty` > 0 e fornecedor. Recusado, o lote inteiro volta 422 sem nenhuma key — é a mesma regra do `canImport()` no `Trades.vue`, que desabilita o botão, agora também no servidor
  - **Origem da tabela:** `trade_lines` nasceu da normalização de `trades.games`, uma coluna JSON que guardava as linhas como array posicional. A migration `2026_08_15_000001` cria a tabela e converte o JSON via `App\Domain\Trades\LegacyTradeLine`; a `2026_08_16_000001` derruba a coluna. `LegacyTradeLine` **fica no código** — sem ela um `migrate:fresh` não replaya o backfill

Fluxo de prospecção/importação completo: [`docs/PRODUCT.md`](../PRODUCT.md) (seção "Fluxo de Compra"). `ProspectSupplierUseCase`, `ExecuteSupplierListUseCase`, `TradeService::paginate()` são as classes de entrada.

> *`Vip`/`VipList` foram absorvidos por `Supplier`/`Trade` (migration `2026_07_05_000001_drop_vips_and_vip_lists_tables.php`) — se algum código ou doc antigo ainda citar esses nomes, é drift, corrija.*

## 7. Fechamento mensal (`FinancialMonth`/`FinancialMovement` → tabelas `financial_months`/`financial_movements`)

Livro-caixa dos sócios em **R$** (`/financial-months`). **Não confundir com o dashboard analítico de vendas em €** (`Services/Sales/SalesDashboardService`, `SalesDashboard.vue`, `/sales`) — domínios distintos, hoje também com nomes distintos.

- `FinancialMonth` — um mês do ciclo `draft` → `closed`. Campos: `year`, `month`, `status` (`FinancialMonthStatus`), `reinvestment_percent`, `emergency_percent`, `partner_one_share` (as três só como **prefill de formulário**), `closed_at`. No máximo um `draft` por vez
- `FinancialMovement` — uma linha do extrato. Campos: `group_id` (uuid — liga as linhas do mesmo lançamento), `account_type` (`AccountType`: `principal`/`tf2`/`reinvestment`/`emergency`), `direction` (`MovementDirection`), `category` (`MovementCategory`), `expense_category` (`ExpenseCategory`, só quando `category = expense`), `income_category` (`IncomeCategory`, só quando `category = income`), `amount` (sempre positivo — a direção decide o sinal), `quantity`/`unit_price` (TF2), `partner_slot` (1 ou 2), `description`, `occurred_at`, `is_generated`
- **Nenhum saldo é persistido** — é sempre a soma dos movimentos (`FinancialMonthService::accountBalances`). Saldo negativo é permitido
- Escrita passa **sempre** pelo `MovementRecorder` quando o lançamento tem mais de uma perna: ele gera um `group_id` único e grava tudo numa transação. É a garantia de que meia transferência nunca é persistida

Regras de negócio completas (roteiro dos 8 passos, exclusão, carry-forward): [`docs/PRODUCT.md`](../PRODUCT.md) e [`docs/adr/0005`](../adr/0005-financial-month-records-instead-of-calculating.md).

## 8. Autorização

- `AuthorizedUsers` — controla acesso (`can-edit`)
- Admin: `Gate::define('is-admin', ...)` em `AppServiceProvider`, comparando contra `config('app.admin_gate_email')` — chave própria, separada de `config('app.admin_email')` (ver [`security-and-guardrails.md`](security-and-guardrails.md#fallback-de-config-depende-do-que-a-ausência-causa))
