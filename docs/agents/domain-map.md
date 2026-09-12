# Mapa de domínios

Referência estrutural rápida — modelo, tabela, campos relevantes. Para entidades, relacionamentos e ciclo de vida em tabelas, veja primeiro [`docs/wiki/DOMAIN.md`](../wiki/DOMAIN.md); para regras de negócio e fórmulas por trás de cada campo, [`docs/PRODUCT.md`](../PRODUCT.md) e [`docs/GAMIVO.md`](../GAMIVO.md) são a fonte única — não repita a regra aqui, só aponte para ela.

## 1. Keys (`Key` → tabela `keys`)

Modelo central. Representa keys compradas e/ou vendidas.

Campos relevantes:
- `claim_type` — enum do tipo de problema que ocorreu na key
- `steam_id` — ID na Steam
- `game_name`, `region` — nome do jogo e região de bloqueio (ex: EU)
- `individual_cost` — custo individual da key; **nunca negativo** (`ProfitCalculator::normalizeCost`, aplicado também no mutator do modelo `Key`)
- `tf2_quantity` — quantidade de TF2 keys pagas pela trade
- `market_price` — preço no marketplace na data de compra
- `simulated_income` — receita líquida após taxas Gamivo
- `purchase_profit`, `purchase_profit_percent` — lucro na compra
- `sold_price`, `sale_profit`, `sale_profit_percent` — dados da venda
- `gamivo_id` — ID externo no marketplace Gamivo. É o **`product_id`** da Gamivo (não o `offer_id`): é por ele que o `AutoSellUseCase` consulta as ofertas do produto e que o `UpdateSoldOffersUseCase` casa cada linha do histórico de vendas com a key entregue
- `key_code` — código da key entregue ao cliente
- `acquired_at`, `listed_at`, `sold_at`, `expires_at` — datas do ciclo de vida
- `supplier_url` — URL do perfil do fornecedor
- `trade_id` — FK → `trades.id` (nullable): a trade/lote de onde a key veio; populado só no import por trade. Usado para recalcular o rateio de custo ao editar (ver [`docs/adr/0004`](../adr/0004-recalculate-trade-on-key-edit.md))
- `min_api`, `max_api` — limites de preço aceitos pela API Gamivo. `min_api` é recalculado diariamente pelo `RegulateMinApiUseCase`; `max_api` é calculado no import (`RegisterKeyUseCase`) e só volta a mudar quando o auto-sell o trava numa key velha — **não existe** um `RegulateMaxApiUseCase`. Ambos são **editáveis à mão** na tela de Keys (`PUT /keys/{key}`, whitelist em `StoreGameRequest`), sem restrição cruzada: `min_api > max_api` é estado legítimo e o clamp resolve com o piso vencendo. A edição de `max_api` persiste; a de `min_api` vale **até as 07:30 do dia seguinte**, quando o scheduler reescreve o piso
- `market_price` — preço de mercado pesquisado no momento da trade. É uma foto **por definição**, não por descuido: ele fixa o rateio de custo e os lucros de compra do lote, então não deve ser atualizado (ver [`docs/adr/0004`](../adr/0004-recalculate-trade-on-key-edit.md)). Além de alimentar custo e lucro, é a âncora de preço quando não há concorrente (ver [`docs/GAMIVO.md`](../GAMIVO.md#sem-concorrente-utilizável-vendedor-único))

Classes-chave: `KeyCalculationService` (fórmulas), `RegisterKeyUseCase` (único caminho de entrada — sempre via `POST /trades/{trade}/import`), `UpdateKeyUseCase` (edição inline). Fluxo completo e agendamentos: [`docs/wiki/AUTOMATIONS.md`](../wiki/AUTOMATIONS.md).

**Toda key nasce de uma trade.** Não existe cadastro avulso (`POST /keys`) nem importação XLSX; `keys.trade_id` é nullable só por causa de keys anteriores a esse vínculo. **A importação é atômica**: `RegisterKeyUseCase` roda o lote inteiro numa transação — cada key roda num savepoint próprio para reportar todos os erros de uma vez, mas se qualquer uma falhar, nada é persistido e a trade não é marcada como importada. `201` quando o lote inteiro entra, `422` quando nada entra — não existe `207 Multi-Status`. Ver [`docs/adr/0004`](../adr/0004-recalculate-trade-on-key-edit.md).

**Editar `market_price` recalcula o lote inteiro.** É o único campo editável que dispara recálculo de `individual_cost`/lucros de todas as keys da mesma trade (via `trade_id`) — outras edições persistem só os campos alterados. Ver [`docs/adr/0004`](../adr/0004-recalculate-trade-on-key-edit.md).

## 2. Cálculo de lucro (`KeyCalculationService` + `Domain/Pricing`)

Tiers de taxa, fórmulas de `simulated_income`, `min_api`/`max_api` e o teto de vendedor único (calculado na hora da decisão, nunca persistido — [`adr/0010`](../adr/0010-sole-seller-ceiling-computed-not-persisted.md)): ver [`docs/GAMIVO.md`](../GAMIVO.md#algoritmos-de-precificação) e a fonte oficial [`docs/GAMIVO_Merchant-pricing.pdf`](../GAMIVO_Merchant-pricing.pdf). Não duplicar a tabela de taxas aqui — ela já teve drift uma vez entre este arquivo e o PDF oficial.

## 3. Bundles

Agrupamento de jogos (`bundle` ou `choice`). Many-to-many com `Game` via `bundle_games`. Regra da janela de exclusão de 21 dias (`KeyEligibility::BUNDLE_EXCLUSION_DAYS`): ver [`docs/wiki/DOMAIN.md#bundle-vs-choice`](../wiki/DOMAIN.md#bundle-vs-choice) e [`docs/GAMIVO.md`](../GAMIVO.md).

**Pesquisa de preço dos jogos do bundle.** `ResearchBundleGamesUseCase` dispara `POST /api/games/research` no `price_researcher` com o payload de `App\Domain\Bundles\BundleResearchRequest` — que guarda os três critérios de negócio do fluxo: `MIN_POPULARITY = 1`, `CHECK_GAMIVO_OFFER = false` e `MIN_PRICE = 0`. Os três são frouxos de propósito — na compra de bundle a decisão é sobre o pacote inteiro, então jogo impopular, barato ou ainda sem oferta na Gamivo também conta. `MIN_PRICE` precisa ir explícito: omitido, o serviço aplica o default de €0,50. É assíncrono: o retorno confirma só o enfileiramento, e o resultado volta minutos depois pelo callback `POST /trades/from-price-researcher`, virando uma trade com o nome do bundle. Contrato completo em [`docs/PRICE_RESEARCHER.md`](../PRICE_RESEARCHER.md).

## 4. Assets (`Asset` → tabela `assets`)

Representa ativos de troca (ex: TF2 key). Campos: `price_euro`, `price_dollar`, `price_brl`. Usado por `KeyCalculationService` para converter o custo da trade em euros.

## 5. Fees (`Fee` → tabela `fees`)

Taxas do marketplace. Campos: `name`, `preco`. Chaves usadas: `gamivoPercentualMenor`, `gamivoFixoMenor`, `gamivoPercentualMaior`, `gamivoFixoMaior`.

## 6. Suppliers e Trades (`Supplier`/`Trade`/`TradeLine` → tabelas `suppliers`/`trades`/`trade_lines`)

- `Supplier` — fornecedor Steam. Campos: `steam_id`, `url`, `region`, `initial_offer_pct`, `is_added` (marcado manualmente como adicionado à lista de trade), `has_traded`, `category` (enum `SupplierCategory`: `vip` | `blocked`)
- `Trade` — registro de uma lista de jogos comentada/ofertada a um supplier. Campos: `supplier_id`, `list_code`, `last_commented_at`, `title`, `date`, `message_sent`, `is_imported`, `tf2_qty`, `supplier_notes`, e as três da entrega: `delivery_uuid`, `delivery_token`, `delivered_at`. `Trade hasMany Key` via `keys.trade_id` — as keys efetivamente compradas daquele lote (populado no `POST /trades/{trade}/import`)
  - As três colunas da entrega ficam **fora do `$fillable`** — quem as grava são os UseCases, por atribuição explícita; e `delivery_token` está em `$hidden`, para não sair numa serialização por acidente
  - `delivery_token` tem cast `encrypted` (não é hash): o par link + código fica à vista na aba para a equipe copiar, e exibi-lo exige poder lê-lo de volta. Sai **só** pela projeção do `TradeService` (`delivery_url` + `delivery_token`), nunca pela da entrega. Ver [`docs/adr/0008`](../adr/0008-supplier-fills-trade-through-tokenised-link.md)
  - Quem lê o token é `Trade::readableDeliveryToken()`, nunca a propriedade direto: o cast lança `DecryptException` quando o valor guardado não abre com a `APP_KEY` corrente (dump de outro ambiente, chave rotacionada), e o token é lido para **toda** trade listada. O método devolve `null` nesse caso — a trade aparece sem código e a aba continua de pé. Um token ilegível também não autentica ninguém: `AuthenticateDeliveryUseCase` o trata como token errado
  - **Toda trade nasce com credencial de entrega:** `CreateTradeUseCase`, `StoreListTradeUseCase` e `ProspectSupplierUseCase` gravam `DeliveryCredential::issue()` (Domain, devolve `delivery_uuid` + `delivery_token`) na mesma transação, por `forceFill` — as colunas não são fillable. A migração preencheu as trades anteriores, e **esses três são os únicos escritores dessas colunas**. Por isso `delivery_uuid` **não** entra na derivação de `TradeDeliveryState` — ter link não distingue trade nenhuma
  - `is_imported` — importar as keys da trade (sem erros) marca `is_imported = true`. A trade importada permanece no banco (o vínculo `keys.trade_id` continua válido — não excluir a trade após importar). A aba de Trades usa `TradeService::paginate(filters, sort, dir, perPage)` com o filtro `view` (`open`/`imported`/`all`); default `open` esconde importadas. A projeção traz `lines_count`, não as linhas. Ver [`docs/adr/0004`](../adr/0004-recalculate-trade-on-key-edit.md)
  - **Reimportação:** `POST /trades/{trade}/import` não é bloqueado por `is_imported = true` — o botão "Importar keys" fica disponível mesmo em trades já importadas (com aviso de confirmação e cor de alerta no frontend), rodando `RegisterKeyUseCase` de novo. Não há exclusão automática das keys antigas: é responsabilidade do usuário apagá-las antes de reimportar, para não duplicar estoque (`KeyRepository::findByKeyCode` apenas marca `is_duplicate = true` em colisão de `key_code`, não bloqueia). `is_imported` continua sendo um boolean simples, sem timestamp/contador de reimportações.
- `TradeLine` — uma **linha da trade** (ver [`CONTEXT.md`](../../CONTEXT.md)): um jogo dentro da trade. Campos: `trade_id` (FK, `cascadeOnDelete` — a linha não existe fora da trade), `position` (ordem de exibição), `game_name`, `market_price` (decimal), `popularity` (int), `region`, `bundle`, `expires_at` (date), `key_code`, `gamivo_id`. Todos nuláveis menos a FK e a posição: linha em branco é estado normal de trade. `Trade hasMany TradeLine` via `lines()`, já ordenado por `position`
  - **Leitura:** `GET /trades/{trade}/lines` (`TradeLineController::index` → `TradeService::linesFor`). A listagem de trades **não** carrega as linhas: manda `lines_count`, e a aba busca as linhas da trade que o usuário abrir. Ver [`docs/adr/0009`](../adr/0009-trade-lines-loaded-on-demand.md)
  - **Escrita:** `TradeLineController` (recurso próprio, aninhado sob a trade), atrás do mesmo `CheckPermission` — `POST /trades/{trade}/lines`, `PATCH /trades/{trade}/lines/{line}`, `DELETE /trades/{trade}/lines/{line}`, o grupo inteiro com `scopeBindings` para a linha de uma trade não ser alcançável pela URL de outra. Entrada tipada por `App\UseCases\Trades\DTO\TradeLineDTO`, montado no `TradeLineRequest::toDTO()`. O `PATCH` é **patch parcial**: o DTO carrega quais colunas o payload trouxe, então mandar vazio limpa e não mandar não toca — é o caminho único de escrita de linha, compartilhado com a entrega pelo supplier. `PUT /trades/{trade}` grava só os campos da própria trade
  - **Escopo de autoridade:** `UpdateTradeLineUseCase::execute()` recebe `App\Domain\Enums\TradeLineAuthority` (`Team` | `Supplier`) — **parâmetro obrigatório, sem padrão** — e intersecta o DTO com `writableColumns()`. `Supplier` alcança `region`, `bundle`, `expires_at` e `key_code`; `Team`, tudo. `bundle` entrou em 2026-08-19: é o único campo pesquisado que o supplier grava, por cima do palpite do lookup. O mesmo enum diz em que formato cada lado lê data (`dateFormat()`: `d/m/Y` para a equipe, `m/d/Y` para o supplier) — mesmo eixo, quem escreveu. É o domínio que recusa, não o Form Request (ver [`docs/adr/0008`](../adr/0008-supplier-fills-trade-through-tokenised-link.md))
  - **`gamivo_id` é derivado do par (`game_name`, `region`)**, e `App\Domain\Trades\GamivoIdentity` diz quando ele deixa de valer: alterar o nome ou a região pela rota de escrita apaga o id da linha. O mesmo jogo é outro produto na Gamivo em cada região, e quem corrige um dos dois no meio da negociação quase sempre trocou o jogo tradado. Duas condições valem citar: **um id diferente na mesma escrita ganha** (corrigiu o jogo e já colou o id certo), e retoque de caixa/espaço não conta — o lookup casa o nome por `LOWER(...)`. Vale para as **duas** autoridades, e não amplia o alcance do supplier: apagar é consequência de mudar a região, coluna que ele já escreve. A limpeza erra para o lado que se corrige — linha sem id volta a ser resolvida no import por `GameService::getIdGamivo`, enquanto id errado atravessa calado e ainda é propagado para `games` por `fillIdGamivo`. `PATCH /trades/{trade}/lines/{line}` responde `{"gamivo_id": ...}` por isso: a aba manda a linha inteira a cada gravação e reenviaria o id apagado
  - **`position`** é mantida contígua (0..n-1): inserir numa posição empurra as seguintes, remover fecha o buraco — ambos em transação. É o que permite duplicar uma linha "logo abaixo da original"
  - **Normalização** por `App\Domain\Trades\TradeLineValue`, compartilhada com o backfill: string vazia vira `null`, e o que não couber na coluna tipada (preço não numérico, data impossível) vira `null` em vez de recusar a gravação — o autosave da aba dispara a cada tecla, e recusar "02/0" transformaria digitação em erro
  - **Import:** `POST /trades/{trade}/import` **não recebe corpo** — o lote sai das linhas gravadas. `RegisterKeyUseCase::execute(Trade)` lê a linha (`game_name`, `market_price`, `region`, `expires_at`, `key_code`, `gamivo_id`) e a trade (`date` → `keys.acquired_at`, `tf2_qty` → `keys.tf2_quantity`, `supplier_id`/`supplier.url` → `keys.supplier_id`/`supplier_url`); o resto da key vem de `KeyDefaults`. Linha em branco não vira key. Antes o navegador mandava o array inteiro, inclusive o `market_price` que define o rateio de `individual_cost` do lote
  - **Prontidão para importar** em `App\Domain\Trades\ImportReadinessPolicy`, com os impedimentos tipados em `App\Domain\Enums\TradeImportBlocker`: linha preenchida (com nome **ou** preço) exige nome, preço > 0 e `key_code`; a trade exige `tf2_qty` > 0 e fornecedor. Recusado, o lote inteiro volta 422 sem nenhuma key — é a mesma regra do `canImport()` no `Trades.vue`, que desabilita o botão, agora também no servidor
  - **Origem da tabela:** `trade_lines` nasceu da normalização de `trades.games`, uma coluna JSON que guardava as linhas como array posicional. A migration `2026_08_15_000001` cria a tabela e converte o JSON via `App\Domain\Trades\LegacyTradeLine`; a `2026_08_16_000001` derruba a coluna. `LegacyTradeLine` **fica no código** — sem ela um `migrate:fresh` não replaya o backfill
- **Entrega pelo supplier** (`/deliveries/{uuid}`) — o próprio supplier preenche `key_code`, `region`, `bundle` e `expires_at` por linha, mais `tf2_qty` e `supplier_notes` da trade, por um link com token. Enviar exige `tf2_qty` > 0 (`ImportReadinessPolicy::hasTf2Quantity`, a mesma régua do import): sem ele o `POST /deliveries/{uuid}/deliver` responde 422 e a trade não entra na fila. `supplier_notes` acumula dois papéis: o caso irregular da trade (jogo de brinde, jogo que ele não tem mais) e o feedback dele sobre a própria página. Decisão completa em [`docs/adr/0008`](../adr/0008-supplier-fills-trade-through-tokenised-link.md)
  - **Rotas** em `DeliveryController`, **fora de `RequireTeam` e de `CheckPermission`** — o usuário aqui não é a equipe: `GET /deliveries/{trade:delivery_uuid}` (a página, em três estados: `locked`, `open`, `completed`), `POST .../token` (confere o token e abre a sessão), `PATCH .../` (campos da trade), `PATCH .../lines/{line}`, `POST .../deliver`. A trade é resolvida pelo `delivery_uuid`, nunca pelo id sequencial; `scopeBindings` e `whereUuid` no grupo — sem a restrição no roteador, uuid malformado chega ao Postgres e vira 500. Um `Route::any('deliveries/{path}')` logo depois do grupo devolve 404 no que sobra, em vez de deixar o `Route::fallback` global redirecionar o supplier para a aba interna. **Não há rota para emitir credencial** — a trade já nasce com a dela, e não existe reemissão
  - **Aviso de entrega:** `MarkTradeDeliveredUseCase` manda `App\Mail\TradeDeliveredMail` para `config('app.admin_email')` no primeiro clique — o mesmo `if` que guarda `delivered_at` evita o segundo e-mail. Envio dentro de `try/catch` com log: falha de SMTP não pode custar a entrega do supplier, e a fila no topo da aba continua sendo o caminho que não depende de e-mail. O e-mail leva contagem, TF2 e recado — **nunca `key_code`**
  - **Middlewares próprios:** `EnsureDeliverySession` (exige a sessão daquela entrega, recusa trade já importada com 404 e trade já entregue com **409** — ver `TradeDeliveryState::acceptsSupplierWrites()`) e `ValidateDeliveryCsrfToken` (repõe a verificação de CSRF, desligada globalmente no projeto) nas escritas
  - **Domínio:** `App\Domain\Trades\DeliveryCredential` guarda formato do token (16 chars Crockford base32), emissão da credencial (`issue()` — uuid v4 + token), limites do rate limit (10/h por entrega, 30/h por IP) e o prazo próprio da sessão (12h — não os 7 dias do `SESSION_LIFETIME`). O token é guardado **encriptado** (cast `encrypted` em `Trade`), não em hash — o SHA-256 aqui é só a marca (`fingerprint`) que amarra a sessão à credencial vigente; `App\Domain\Enums\TradeDeliveryState` deriva o estado da entrega das colunas, sem coluna de status (`Trade::deliveryState()` é o acessor), e é ele quem define a janela de escrita do supplier: `acceptsSupplierWrites()` só é verdadeiro em `Negotiating` — entregar fecha a página
  - **Leitura em duas camadas:** `App\Services\Trades\DeliveryReadModel` faz o `select()` explícito (`market_price`, `popularity` e `gamivo_id` **nunca** são selecionados — é a saída do `price_researcher`; `bundle` é a única exceção, exibido desde 2026-08-19 — é a pista do region lock da key, e o supplier já sabe que a key dele veio de bundle: mostrar isso ancora a negociação para baixo, não para cima) e `DeliveryTradeResource` molda. Ver a seção "Uma escrita, duas leituras" do ADR
  - **Rate limit:** dois eixos em `AuthenticateDeliveryUseCase` (por entrega e por IP). O 429 devolve o tempo restante no header `Retry-After` e no texto da mensagem — a janela é de uma hora, e sem o número o supplier volta cedo demais e conclui que o link quebrou
  - **Na aba de Trades:** `TradeService::VIEW_AWAITING_REVIEW` é a fila de conferência; em `open` as entregues sobem para o topo, e `awaitingReviewCount` alimenta a contagem no rótulo do filtro

**Como cada caminho do `price_researcher` resolve o bundle da linha.** Todos passam um mapa (nome normalizado do jogo → nome do bundle) ao `TradeLineBuilder::fromResearch`; o que muda é de onde o mapa vem — da ordem mais confiável para a menos:

| Caminho | Origem do mapa | Por quê |
|---|---|---|
| Pesquisa disparada de um bundle (trade **sem** supplier) | `BundleService::bundleByTitle()` — o `title` do callback nomeia o bundle, e **toda** linha é dele | Nós sabemos de que bundle os jogos saíram; não é palpite |
| Lista comentada (`StoreListTradeUseCase` com supplier) e prospecção (`ProspectSupplierUseCase`) | `BundleService::recentBundleByGameNames()` — query batelada, janela de `BundleGameLookup::RECENT_MONTHS` | A origem da key é desconhecida; casa por nome + recência |

O título só vale quando **não há supplier**: a lista comentada manda `title` também, e ali ele é o nome da lista no SteamTrades — lista chamada "Humble Choice" não faz de todo jogo dela um jogo de bundle. Sem bundle nomeado pelo título, o caminho cai no palpite por nome.

Na prospecção a consulta fica **dentro** do `if ($shouldComment)`: ela avalia muitos perfis e comenta poucos, e resolver antes cobraria uma query por perfil avaliado.

Fluxo de prospecção/importação completo: [`docs/PRODUCT.md`](../PRODUCT.md) (seção "Fluxo de Compra"). `ProspectSupplierUseCase`, `ExecuteSupplierListUseCase`, `TradeService::paginate()` são as classes de entrada.

> *`Vip`/`VipList` foram absorvidos por `Supplier`/`Trade` (migration `2026_07_05_000001_drop_vips_and_vip_lists_tables.php`) — se algum código ou doc antigo ainda citar esses nomes, é drift, corrija.*

## 7. Fechamento mensal (`FinancialMonth`/`FinancialMovement` → tabelas `financial_months`/`financial_movements`)

Livro-caixa dos sócios em **R$** (`/financial-months`). **Não confundir com o dashboard analítico de vendas em €** (`Services/Sales/SalesDashboardService`, `SalesDashboard.vue`, `/sales`) — domínios distintos, hoje também com nomes distintos.

- `FinancialMonth` — um mês do ciclo `draft` → `closed`. Campos: `year`, `month`, `status` (`FinancialMonthStatus`), `reinvestment_percent`, `emergency_percent`, `partner_one_share` (as três só como **prefill de formulário**), `closed_at`. No máximo um `draft` por vez
- `FinancialMovement` — uma linha do extrato. Campos: `group_id` (uuid — liga as linhas do mesmo lançamento), `account_type` (`AccountType`: `principal`/`tf2`/`reinvestment`/`emergency`), `direction` (`MovementDirection`), `category` (`MovementCategory`), `expense_category` (`ExpenseCategory`, só quando `category = expense`), `income_category` (`IncomeCategory`, só quando `category = income`), `amount` (sempre positivo — a direção decide o sinal), `quantity`/`unit_price` (TF2), `partner_slot` (1 ou 2), `description`, `occurred_at`, `is_generated`
- **Nenhum saldo é persistido** — é sempre a soma dos movimentos (`FinancialMonthService::accountBalances`). Saldo negativo é permitido
- **Leitura em dois níveis:** `FinancialMonthService::overview()` monta a página e carrega os movimentos **só** do draft; cada mês fechado vai como cabeçalho, e o extrato dele vem de `GET /financial-months/{financialMonth}` (`show()` → `details()`) quando alguém abre os Detalhes — mesmo critério das linhas de trade ([`docs/adr/0009`](../adr/0009-trade-lines-loaded-on-demand.md)). `details()` serve mês fechado e draft sem distinção. A rota é leitura, mas fica no grupo `CheckPermission` como as demais: é o caixa da operação
- **No front:** o vocabulário do fechamento (rótulos, tipos, `tf2SummaryOf`, `totalBalanceOf`, `isDeletable`) mora em `resources/js/helpers/financial.ts`, e a tabela do extrato em `components/financial/MonthMovementsTable.vue` (`deletable` liga a coluna de apagar) — a página e o modal `MonthDetailsDialog.vue` leem o mesmo mês, e rótulo duplicado é como as duas telas passam a discordar
- Escrita passa **sempre** pelo `MovementRecorder` quando o lançamento tem mais de uma perna: ele gera um `group_id` único e grava tudo numa transação. É a garantia de que meia transferência nunca é persistida

Regras de negócio completas (roteiro dos 8 passos, exclusão, carry-forward): [`docs/PRODUCT.md`](../PRODUCT.md) e [`docs/adr/0005`](../adr/0005-financial-month-records-instead-of-calculating.md).

## 8. Autorização

- `AuthorizedUsers` — controla acesso (`can-edit`)
- Admin: `Gate::define('is-admin', ...)` em `AppServiceProvider`, comparando contra `config('app.admin_gate_email')` — chave própria, separada de `config('app.admin_email')` (ver [`security-and-guardrails.md`](security-and-guardrails.md#fallback-de-config-depende-do-que-a-ausência-causa))

## 9. Soft-delete (conjunto curado)

Rede de proteção contra apagamento acidental. `deleted_at` + trait `SoftDeletes` em **7 tabelas**: `keys`, `trades`, `suppliers`, `games`, `bundles`, `financial_months`, `financial_movements`.

**Fora, de propósito:** `trade_lines` (a exclusão de linha reindexa a `position` das irmãs — uma linha soft-deletada guardaria a posição antiga e o `restore()` duplicaria posição), `bundle_games` (pivot), `fees`/`assets`/`authorized_users` (lookup) e `users` (o provider de auth usa `newModelQuery()`, que não aplica o global scope).

Sem UI de lixeira — `restore()` é manual. Detalhes, índice `unique` parcial e armadilhas em [`docs/adr/0011`](../adr/0011-soft-delete-on-curated-tables.md).
