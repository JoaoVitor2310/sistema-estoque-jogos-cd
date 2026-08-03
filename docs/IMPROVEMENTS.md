# IMPROVEMENTS — pendências do sistema

Fonte única de tudo que ainda deve ser feito no sistema: roadmap, features
planejadas, melhorias de qualidade e dívida técnica de code-review. Centraliza o
que antes estava espalhado no `CLAUDE.md`, `docs/GAMIVO.md`, `docs/PRODUCT.md` e
comentários de código. Cada entrada referencia onde mexer (**Onde**), o que fazer
(**Ação**) e de onde veio (**Origem**).

Ordem: roadmap/qualidade/features primeiro, dívida técnica de code-review no fim.

---

## FinancialMonth — fechamento mensal e distribuição entre sócios (spec)

**Onde:** `app/Domain/Financial/`, `app/Domain/Enums/` (`AccountType`, `MovementCategory`, `MovementDirection`, `FinancialMonthStatus`), `app/UseCases/Financial/`, `app/Services/Financial/`, `app/Models/` (`FinancialMonth`, `FinancialMovement`), `app/Http/Controllers/Financial/`, `app/Http/Requests/`, `resources/js/Pages/FinancialMonths.vue`, `routes/web.php`, `database/migrations/`. **Não** tocar em `FinancialService`/`Financial.vue` (dashboard analítico em €, domínio distinto — compartilham só o prefixo).

**Problema:** todo mês os dois sócios reconstroem o financeiro na mão — reúnem entradas/saídas, separam a verba de TF2, abastecem as caixinhas e chegam ao saque de cada sócio. Trabalhoso, sem histórico auditável, cada mês recomeça do zero.

**Solução:** domínio `FinancialMonth` (`/financial-months`) — um **livro-caixa de 4 contas** onde o mês é montado lançamento a lançamento, e "fechar" apenas encerra o mês e abre o próximo. Termo falado: "fechamento mensal"; a entidade cobre o ciclo `draft` → `closed`, com `close`/`reopen` como atos.

> **Estado atual:** existe código não commitado no working tree implementando uma versão **anterior** desta spec (com cascata automática). Os tickets abaixo são **diffs sobre esse código**, não trabalho do zero — ver "Mapeamento de tickets".

### Modelo de 4 contas

Quatro saldos (`AccountType`: `principal` / `reinvestment` / `emergency` / `tf2`), cada um com movimentos (`FinancialMovement`: crédito/débito, `category`, justificativa). **Nenhum saldo é persistido** — é sempre a soma dos movimentos do mês. Saldo da empresa = soma dos quatro.

- **Principal**: caixa operacional. Entra faturamento (saque da Gamivo + receitas avulsas); saem a verba de TF2, despesas operacionais, transferências p/ as caixinhas e distribuições aos sócios.
- **TF2**: verba do mês para comprar TF2 keys. Recebe a alocação do Principal; as compras reais debitam daqui. A sobra volta ao Principal no fechamento.
- **Reinvestimento / Emergência**: caixinhas. Recebem transferências explícitas; débitos exigem justificativa.

### Fluxo do mês (roteiro, não invariante — nada é bloqueado)

| # | Ação | Efeito | Categoria |
|---|---|---|---|
| 1 | Registrar o saque da Gamivo | crédito na conta escolhida (normalmente Principal) | `income` |
| 2–3 | Definir a meta de TF2 (qtd × preço unitário) | débito no Principal + crédito no TF2 | `tf2_allocation` |
| 4 | Lançar gastos (impostos, Claude, etc.) | débito na conta escolhida | `expense` |
| 4 | Abastecer o Reinvestimento | débito Principal + crédito Reinvestimento | `transfer` |
| 5 | Abastecer a Emergência | débito Principal + crédito Emergência | `transfer` |
| 6 | Sacar para os sócios | dois débitos na conta escolhida (um por sócio) | `partner_distribution` |
| 7 | Comprar TF2 ao longo do mês (qtd × preço) | débito no TF2 | `tf2_purchase` |
| 8 | Fechar o mês | devolve a sobra do TF2 e abre o próximo draft | `transfer` (gerado) |

**Nada é distribuído automaticamente.** Cada passo é um lançamento explícito que o usuário confirma. Quem usa porcentagem (passos 4, 5, 6) a aplica sobre o **saldo atual da conta de origem** — seguindo esta ordem, isso reproduz naturalmente a antiga cascata (o Principal já está pós-TF2 e pós-gastos quando os 20% incidem), sem o sistema precisar rastrear degrau nenhum.

### Decisões-chave

- **Verba de TF2 é conta real, não earmark.** O dinheiro sai do Principal de verdade e vive numa quarta conta. *(Reverte a decisão anterior de earmark virtual — ver [ADR 0005](adr/0005-financial-month-records-instead-of-calculating.md).)*
- **Meta de TF2 mora no movimento.** Definir a meta *é* lançar um `tf2_allocation` com `quantity` × `unit_price`. Não há coluna de meta declarada que possa divergir do dinheiro movido; dá para complementar o orçamento no meio do mês com um segundo lançamento. O formulário pré-preenche com o mês anterior (quantidade total alocada + último preço unitário). **Não existe incremento automático** de meta.
- **Transferência é genérica.** Uma categoria `transfer` para qualquer par de contas — o par (*Principal→Reinvestimento*) já declara a intenção, sem categoria redundante que possa contradizer as contas. Sempre dupla partida: débito na origem + crédito no destino.
- **Justificativa protege as caixinhas.** `description` é obrigatória em qualquer movimento que **debite** Reinvestimento ou Emergência, seja `transfer` ou `expense`.
- **Distribuição aos sócios:** uma ação (valor + conta de origem + % do Sócio 1) gera **dois** débitos; o Sócio 2 leva o resto exato, então a soma reconcilia e o **centavo órfão fica com o Sócio 1**. Cada débito traz `partner_slot` (1 ou 2) — **nomes de sócio não são guardados**, a tela exibe "Sócio 1"/"Sócio 2" e a `description` fica livre para observação real.
- **Fechar não distribui.** O `close` gera **um único** movimento: um `transfer` TF2→Principal com a sobra da verba, no mês que encerra. O TF2 fecha em zero e o carry-forward fica uniforme (toda conta abre o mês seguinte com o próprio saldo). Se a verba foi **estourada** (saldo TF2 negativo), a "devolução" vira débito no Principal — que é o comportamento correto.
- **Ciclo:** no máximo um `draft` (o corrente). Bootstrap é o único caminho manual de criação e recusa rodar se **qualquer** mês existir; do segundo mês em diante o draft nasce só do fechamento.
- **Carry-forward:** o novo draft herda as 3 porcentagens (só como **prefill de formulário**, nunca aplicadas sozinhas) e os saldos (como movimentos `opening`).
- **Correção:** apagar lançamento **por grupo** (`group_id`), só em mês `draft`. Um lançamento pode gerar mais de uma linha no livro-caixa — uma transferência grava o débito na origem *e* o crédito no destino. Apagar só uma delas criaria ou destruiria dinheiro (apagar só o débito de uma transferência de R$ 500 devolve os 500 à origem sem tirá-los do destino, inflando o total da empresa em R$ 500). O `group_id` marca as linhas nascidas do mesmo lançamento para que sumam juntas. Sem edição. Mês `closed` é imutável.
- **Reabrir** só o fechamento mais recente: apaga o draft seguinte, desfaz os movimentos `is_generated` (a devolução do TF2 e as aberturas) e volta o status.
- **Saldo negativo é permitido**, sinalizado em vermelho — comprar acima da meta é decisão de negócio legítima, e as contas são baldes internos, não contas bancárias.
- **Sócios:** exatamente 2, identificados por posição (Sócio 1 / Sócio 2) — sem cadastro de nome. Só o split é configurável (padrão 50/50).
- **Permissões:** página `RequireAuth`; mutações `CheckPermission`; enums via `Rule::enum()`. Guest bloqueado (cobrir em `GuestAccessTest`).
- **Moeda:** R$, sem cruzamento com € do dashboard.

### Testes (3 camadas)

- **Unit** (`tests/Unit/Domain/Financial/`): `Money` (já existe), `ManualMovement` (categoria → conta/direção, valor derivado de qtd × preço) e `PartnerSplit` (divisão por %, centavo órfão no Sócio 1, reconciliação exata).
- **Integration** (`tests/Feature/UseCases/Financial/`): cada UseCase via `app()` — bootstrap, lançamento de cada categoria, transferência em dupla partida, distribuição, exclusão por grupo, `close` (devolução da sobra + próximo draft) e `reopen`.
- **Feature** (`tests/Feature/Financial/`, `tests/Feature/Security/`): contratos HTTP + validação (422) + acesso guest/autorizado.

### Fora de escopo

Tela de "Configurações" global; vínculo das compras de TF2 ao domínio de trades/keys; > 2 sócios; scheduler de fechamento; câmbio €↔R$; edição de lançamento; relatório de total sacado por sócio (a `description` cobre a consulta pontual); imposição da ordem dos passos.

### Vocabulário para `CONTEXT.md` (ao implementar)

`FinancialMonth` (fechamento mensal), `AccountType` (Principal / TF2 / Reinvestimento / Emergência), `FinancialMovement`, `tf2_allocation` (verba do mês) vs. `tf2_purchase` (compra real), `transfer`, `partner_distribution`, `close`/`reopen` como atos, `draft`/`closed` como estados.

---

### Mapeamento de tickets

O working tree já contém a implementação da spec anterior (cascata automática), **não commitada**. Como merge na `main` dispara deploy, o modelo antigo não deve ser commitado — os tickets abaixo reescrevem o working tree e só o resultado final vira commit.

**Sobrevive intacto:** `Money`, os dois Models, as migrations base, a camada HTTP (rotas/controller/FormRequests com `toDTO()`/`toManualMovement()`), `FinancialMonthService` (leitura CQRS), o esqueleto de bootstrap/close/reopen e a maior parte dos testes.

| # | Ticket | O que muda | Testes |
|---|---|---|---|
| **R1** | **Migration + enums** | Migration nova: dropar as 9 colunas de snapshot, `tf2_target_quantity`, `tf2_price`, `tf2_increment`, `partner_one_name`, `partner_two_name`; adicionar `group_id` (uuid, nullable) e `partner_slot` (tinyint, nullable) em `financial_movements`. `AccountType` ganha `tf2`; `MovementCategory` perde `reinvestment_transfer`/`emergency_transfer`/`fund_withdrawal` e ganha `transfer`/`tf2_allocation`. Deletar `FinancialMonthDefaults::TF2_MONTHLY_INCREMENT`. | Unit (enums) |
| **R2** | **Domain: `PartnerSplit`, `ManualMovement`** | Extrair `PartnerSplit` do `FinancialMonthCalculator` (divisão + centavo órfão) e **deletar** `FinancialMonthCalculator`, `FinancialMonthResult` e `FinancialMonthCalculatorTest`. Reescrever `ManualMovement`: conta escolhida em `income`/`expense`, `tf2_purchase` debitando TF2, `tf2_allocation` e `transfer` novos, justificativa obrigatória em débito de caixinha. | Unit |
| **R3** | **UseCases de lançamento** | `RecordMovementUseCase` aceita conta escolhida. Novos: `RecordTransferUseCase` (dupla partida + `group_id`, aceita valor ou % do saldo da origem), `RecordTf2AllocationUseCase`, `DistributeToPartnersUseCase` (2 débitos + `PartnerSplit` + `group_id` + `partner_slot`). | Integration |
| **R4** | **Apagar lançamento** | `DeleteMovementGroupUseCase` — apaga o grupo inteiro, só em mês `draft`, recusa em `closed`. | Integration |
| **R5** | **Fechar/reabrir** | `CloseMonthUseCase` perde a cascata inteira: gera só o `transfer` de devolução do TF2, marca `closed` e abre o próximo draft (carry-forward uniforme + prefills). `ReopenFinancialMonthUseCase` perde a limpeza de snapshot. `CreateDraftFinancialMonthUseCase`/`BootstrapFinancialMonthDTO` perdem meta de TF2 e nomes dos sócios, e ganham saldo de abertura da 4ª conta. | Integration |
| **R6** | **HTTP** | `FinancialMonthController` ganha endpoints de transferência, alocação, distribuição e exclusão; FormRequests correspondentes com `Rule::enum()`; rotas + `GuestAccessTest`. | Feature |
| **R7** | **Frontend** | `FinancialMonths.vue`: 4º card de saldo, negativo em vermelho, formulários por ação (com prefill de TF2 e das %), ordem do roteiro na tela, botão de apagar lançamento, histórico sem as colunas de cascata. | — |
| **R8** | **Doc viva** | `CONTEXT.md` (vocabulário), `CLAUDE.md` (domínios/arquitetura), `docs/PRODUCT.md` (fluxo dos 8 passos) e remover esta spec daqui. O ADR [`0005`](adr/0005-financial-month-records-instead-of-calculating.md) já está escrito. | — |

**Ação:** implementar por ticket, limpando o contexto entre eles; R1→R5 encadeiam, R6 depende de R5, R7 de R6.

**Origem:** `/grill-with-docs` → `/to-spec` (2026-07-27); revisado por `/grill-with-docs` (2026-08-01) após simulação de uso real — o dono do produto identificou que o fechamento automático invertia a ordem real das ações e que a verba de TF2 precisa ser uma conta de verdade. 13 decisões validadas na entrevista.

---

## Qualidade de código — endurecer o PHPStan

**Onde:** `phpstan.neon`, `composer.json`.

Já concluído: PHPStan (`phpstan/phpstan ^2.1`) e Pint rodam no CI (`.github/workflows/ci.yml` → jobs Pint, PHPStan, Pest), com `phpstan.neon` em **nível 7** cobrindo apenas `app/Domain`.

**Ação (pendente):**
- [ ] Subir o nível de `app/Domain` para 8
- [ ] Estender a análise ao restante de `app/` (ex: nível 5)
- [ ] Avaliar adicionar Larastan (`larastan/larastan`) para regras específicas de Laravel

**Origem:** roadmap do `CLAUDE.md` — a instalação base já estava concluída (roadmap estava desatualizado); sobra só o endurecimento.

---

## Mover `tf2_quantity` de `keys` para `trades`

**Onde:** `database/migrations/` (nova migration), `app/Models/Key.php`, `app/Models/Trade.php`, `app/Domain/Pricing/ProfitCalculator.php` (`individualCost`), `app/UseCases/Keys/RegisterKeyUseCase.php`, `app/Services/FinancialService.php` (`getTf2Spent`).

`tf2_quantity` é o total de TF2 keys pago pela **trade**, não por cada key — hoje está duplicado em toda key do lote (mesmo valor repetido) e não deveria ser editável no nível da key. O lugar correto é `trades.tf2_qty` (que já existe). O rateio de `individual_cost` passaria a ler a quantidade da trade, e o `getTf2Spent` deixaria de precisar deduplicar por `(total_paid, acquired_at)`.

**Ação:** migrar o valor para `trades`, ajustar o cálculo de rateio para ler da trade, e remover a coluna de `keys` (Expand-Contract). Enquanto não migra, `tf2_quantity` **não** deve ser editável na tela de keys.

**Origem:** decisão ao gatilhar o recálculo do `UpdateKeyUseCase` por `market_price` (2026-07-24).

---

## Remover `supplier_url` de `keys`

**Onde:** `app/Models/Key.php`, `app/Http/Resources/KeyResource.php`, `app/UseCases/Keys/RegisterKeyUseCase.php`, `app/UseCases/Keys/UpdateKeyUseCase.php`, `app/Http/Requests/ImportTradeKeysRequest.php`, `app/Http/Requests/StoreGameRequest.php`.

Campo redundante; o vínculo real é `keys.supplier_id → suppliers.id → suppliers.url`.

**Ação:** garantir que todos os `supplier_id` estejam preenchidos → remover leituras/escritas de `supplier_url` → migration `dropColumn('supplier_url')`.

**Origem:** roadmap do `CLAUDE.md`.

---

## Normalizar FK entre `keys` e `games`

**Onde:** `app/Models/Key.php` (`game()`, `scopeWithoutRecentBundle`), `app/UseCases/Keys/RegisterKeyUseCase.php`, migrations.

Hoje o vínculo é por string: `keys.gamivo_id ←→ games.gamivo_id`. Não há integridade referencial, JOINs são em varchar e `game_name`/`region` ficam duplicados em `keys`.

**Ação (Expand-Contract):**
1. Migration EXPAND: adicionar `game_id` (bigint nullable, FK → `games.id`) em `keys`
2. Migration MIGRATE: backfill via `gamivo_id`
3. `RegisterKeyUseCase` passa a persistir `game_id`
4. Auditar keys órfãs → tornar `game_id` NOT NULL
5. Reescrever `game()` para `belongsTo(Game::class)` padrão
6. Reescrever `scopeWithoutRecentBundle` com FK integer
7. Avaliar remoção de `game_name`/`region` de `keys` (dados denormalizados)
8. Migration CONTRACT: remover `gamivo_id` de `keys`

**Origem:** roadmap do `CLAUDE.md`; também citado em `docs/PRODUCT.md`.

---

## `PriceWholesaleUseCase` — venda no atacado (wholesale)

**Onde:** `app/UseCases/Marketplaces/Gamivo/` (a criar).

Modalidade de venda em atacado (wholesale, divisor `1.035`), ainda não implementada. Ver conceito em `docs/GAMIVO.md` e no termo "Wholesale" do `CONTEXT.md`.

**Origem:** roadmap da migração Gamivo (`docs/GAMIVO.md`).

---

## Expiração — remover oferta da Gamivo no dia em que expira

**Onde:** fluxo de expiração (scheduler / `KeyService`).

Quando faltam 30 dias, o sistema já envia alerta por e-mail e a `MinimumMarginPolicy` rebaixa o `min_api` ao piso. Falta: no dia em que a key expira, remover a oferta da Gamivo e avisar por e-mail.

**Origem:** `docs/PRODUCT.md`.

---

## Estrutura para um segundo marketplace (multi-marketplace)

**Onde:** `app/UseCases/Marketplaces/`, `app/Domain/Pricing/`.

Hoje o sistema opera **exclusivamente na Gamivo**. Diretrizes para quando entrar um segundo marketplace:

- Use cases de marketplace vivem em `UseCases/Marketplaces/Gamivo/` — um novo marketplace ganha `UseCases/Marketplaces/Eneba/` etc., sem tocar nos use cases agnósticos de `UseCases/Keys/`.
- `Domain/Pricing` está acoplado implicitamente à Gamivo (`IncomeCalculator::forGamivo()`, `MarketplaceFee`, constantes de `ComparisonAlgorithm`). **Não abstrair antes de haver um segundo marketplace real** (YAGNI) — abstrair só quando existir a segunda implementação.

**Origem:** `CLAUDE.md` (seção Arquitetura).

---

## `KeyPlatform::fromKeyFormat` — placeholder morto

**Onde:** `app/Domain/Enums/KeyPlatform.php`.

`KeyPlatform::fromKeyFormat()` duplica a lógica de `Domain/Platform/PlatformIdentifier::identify()` (a versão usada pelo app). O método do enum só é exercitado pelo próprio teste (`tests/Unit/Domain/Enums/KeyPlatformTest.php`), nunca pelo código de produção.

**Ação:** avaliar remover `KeyPlatform::fromKeyFormat()` e seu teste, deixando `PlatformIdentifier` como fonte única; se o enum precisar de um helper de plataforma, delegar a `PlatformIdentifier`.

**Origem:** varredura de pendências (2026-07-21); o comentário "Fase 2" no enum estava stale — a extração para `PlatformIdentifier` já foi feita.

---

## Falsy-zero em RegisterKeyUseCase (gamivo_id / steam_id)

**Onde:** `app/UseCases/Keys/RegisterKeyUseCase.php:84-107` (mesmo padrão em `app/UseCases/Keys/UpdateKeyUseCase.php`)

`empty($game['gamivo_id'])` e `empty($game['steam_id'])` tratam a string `'0'`
como ausente. Um `gamivo_id` ou `steam_id` literal `'0'` (erro de digitação,
scrape malformado) seria silenciosamente descartado ou dispararia uma busca
externa desnecessária, e `fillIdGamivo`/`fillSteamId` nunca seriam chamados
para propagar o valor.

Baixa probabilidade prática (IDs reais da Gamivo/Steam não são `'0'`), mas
ficou mais alcançável depois que o campo `gamivo_id` passou a ser preenchido
por texto livre no `Trades.vue` e enviado diretamente pelo `price_researcher`.

**Ação:** trocar os checks `empty(...)` por `$game['gamivo_id'] === null || $game['gamivo_id'] === ''` (ou equivalente que não trate `'0'` como vazio).

**Origem:** code-review da feature `gamivo_id` em trades (2026-07-17).

---

## Duplicação de helpers de seed em testes

**Onde:**

- `tests/Feature/Trades/ImportTradeKeysTest.php::seedImportFees()`
- `tests/Feature/Keys/RegisterKeyUseCaseTest.php::seedRegisterFks()`
- `tests/Feature/Suppliers/SupplierProspectTest.php::seedProspectDeps()`
- `tests/Feature/UseCases/Suppliers/ProspectSupplierUseCaseTest.php::seedUseCaseDeps()`

Todas inserem os mesmos valores de fees Gamivo (`gamivo_percent_low`,
`gamivo_fixed_low`, `gamivo_percent_high`, `gamivo_fixed_high`) e do asset TF2,
com pequenas variações de formatação. Também há duplicação entre
`makeAuthorizedImportUser()` (novo) e `makeAuthorizedFinancialUser()`
(`tests/Feature/Financial/FinancialDashboardTest.php`) — ambos criam um
`User::factory()` + `AuthorizedUsers`.

Uma mudança nos valores de fee ou na lógica de autorização exige atualizar
múltiplos arquivos independentemente; um esquecimento produz testes que
passam com premissas desatualizadas em vez de falhar.

**Ação:** extrair para helpers compartilhados (`tests/Pest.php` ou um arquivo
de suporte em `tests/Support/`) — `seedGamivoFees()` e `actingAsAuthorizedUser()`.

**Origem:** code-review da feature `gamivo_id` em trades (2026-07-17).

---

## Regra de validação `games.*.gamivo_id` duplicada em 4 FormRequests

**Onde:**

- `app/Http/Requests/ImportTradeKeysRequest.php`
- `app/Http/Requests/ProspectSupplierRequest.php`
- `app/Http/Requests/StoreListTradeRequest.php`
- `app/Http/Requests/GameRequestArray.php` (pré-existente)

`'games.*.gamivo_id' => ['nullable', 'string']` está copiada identicamente em
quatro classes. Um endurecimento futuro da regra (ex: exigir apenas dígitos)
precisaria ser aplicado em todas — esquecer uma deixa pontos de entrada com
validação inconsistente.

**Ação:** avaliar um trait/rule-set compartilhado para os campos comuns de
`games.`* entre esses FormRequests, ou um Value Object de validação.

**Origem:** code-review da feature `gamivo_id` em trades (2026-07-17); padrão
de duplicação já existia antes desse trabalho (region, popularity, price_euro
também são copiados entre as mesmas classes).

---

## Mapeamento `gamivo_id` → `gamivoId` duplicado entre UseCases

**Onde:**

- `app/UseCases/Suppliers/ProspectSupplierUseCase.php::buildGames()`
- `app/UseCases/Trades/StoreListTradeUseCase.php::buildGames()`

Os dois métodos `buildGames()` fazem a mesma conversão snake_case → camelCase
para persistir o JSON de `Trade.games` (`name`, `marketPriceRaw`, `regionLock`,
`keyCode`, `gamivoId`, etc.), de forma independente. Uma mudança no formato
armazenado exige editar os dois em paralelo.

Abaixo do limiar de 3+ chamadas que o CLAUDE.md define para justificar
extração de wrapper — por isso não foi extraído agora — mas vale observar se
um terceiro ponto de entrada precisar do mesmo mapeamento no futuro, momento
em que a extração passa a se justificar.

**Ação:** nenhuma agora. Reavaliar extração de um mapper compartilhado
(`TradeGameMapper::fromIntakeArray()`) se surgir um terceiro caller.

**Origem:** code-review da feature `gamivo_id` em trades (2026-07-17).

---

## `findMinMaxByGamivoId` — alinhar à regra da governante FIFO

**Onde:** `app/Services/Keys/KeyRepository.php::findMinMaxByGamivoId()`, consumido por
`app/UseCases/Marketplaces/Gamivo/UpdateOffersUseCase.php` (`MinMaxPriceCalculator::clamp`).

O `AutoSellUseCase` já lista keys do mesmo `gamivo_id` agrupadas por venda **FIFO**:
a key mais antiga aprovada (**menor `id`** — governante) define o `seller_price` de
toda a oferta, e o upload segue ordem de `id` ASC (implementado em 2026-07-20).

Mas o `UpdateOffersUseCase`, que reprecifica a oferta minutos depois, ainda usa
`findMinMaxByGamivoId` — que agrega `MIN(min_api)`/`MAX(max_api)` entre **todas** as
keys listadas do produto, uma política **diferente** da governante. Consequência
concreta: uma oferta cuja governante é uma key velha (com `max_api` travado baixo na
listagem) pode ter o preço reajustado **para cima** pelo reprecificador, porque o
`MAX(max_api)` das keys mais novas ainda é alto — derrotando parcialmente o propósito
da trava de `max_api` das keys velhas.

**Ação:** substituir `findMinMaxByGamivoId` por uma consulta que retorne os limites
da **governante** (menor `id` entre as keys listadas e não vendidas do `gamivo_id`),
alinhando o `UpdateOffersUseCase` à mesma regra FIFO do `AutoSellUseCase`. Avaliar
remover o método agregado após a migração de todos os callers.

**Origem:** code-review do agrupamento por `gamivo_id` no `AutoSellUseCase` (2026-07-20);
regra FIFO definida pelo dono do produto.

---

## KeySaleController::autoSell — endpoint HTTP síncrono para retomar no futuro

**Onde:** `app/Http/Controllers/Keys/KeySaleController.php::autoSell()`, rota
registrada em `routes/web.php:146` (`GET /auto-sell`, protegida por
`VerifySecret::class`, sem `CheckPermission`).

A rota ainda existe no código, mas não está sendo chamada externamente hoje
(o plano é reativar esse fluxo HTTP futuramente — para um serviço externo
disparar o auto-sell via requisição, em vez de depender só do
`artisan gamivo:auto-sell` via cron).

Quando esse caller externo for reintroduzido, `AutoSellUseCase::execute()`
roda de forma **síncrona dentro do ciclo de request-response** (`$listed = $this->autoSellUseCase->execute();` em `autoSell()`, sem dispatch para queue).
Isso é potencialmente perigoso para lotes grandes: cada key já custa até
~6s (delay de criação de oferta + polling de `isKeyListed`), e com o retry de
action-lock adicionado em `GamivoApiService::sendWithActionLockRetry()` cada
key pode custar até ~24s adicionais sob contenção (múltiplas mutações × até
8s de retry cada). Com centenas de keys elegíveis (429 num único log
observado), a requisição pode facilmente estourar o timeout do PHP-FPM/nginx
antes de terminar — e o cliente HTTP nunca saberia se as keys já foram
listadas ou não.

**Ação:** ao reativar o caller externo, mover a execução para uma queued job
(`AutoSellUseCase` disparado via `Bus::dispatch`) e o endpoint retornar
202/job id de imediato, ou paginar/limitar o lote processado por requisição.
Não implementar antes de haver um caller real definido — evitar design
especulativo.

**Origem:** code-review do fix de action-lock em `GamivoApiService` (2026-07-20).

---

## UpdateOffersUseCase — sobreposição de execuções agendadas

**Onde:** `routes/console.php:48-54` (dois `Schedule::call()` para
`UpdateOffersUseCase::execute()`).

Dois problemas distintos de sobreposição, ambos agravados (não causados) pelo
retry de action-lock adicionado em `GamivoApiService::sendWithActionLockRetry()`
— cada `updateOffer()` sob contenção agora pode bloquear até ~8s a mais por
produto, aumentando a chance de uma execução ainda estar rodando quando a
próxima é disparada:

1. **Auto-sobreposição:** nenhum dos dois `Schedule::call()` usa
  `->withoutOverlapping()`. Se o job de `WeAreLowest` (a cada 5 min) demorar
   mais que 5 minutos — mais provável agora com o retry —, o próximo disparo
   começa uma segunda execução concorrente sobre as mesmas ofertas.
2. **Colisão entre os dois modos:** `*/5 * * * `* (WeAreLowest) dispara nos
  minutos 0, 5, 10, 15... e `5 * * * *` (WeAreNotLowest) dispara no minuto 5
   de cada hora — ou seja, **os dois modos rodam simultaneamente todo minuto
   :05**, processando as mesmas ofertas ativas ao mesmo tempo. Antes do fix
   de retry, essa colisão provavelmente já causava o 400 "Wait for the
   current action" silenciosamente engolido pelo `updateOffer` antigo (preço
   não aplicado, sem erro visível). Agora ela aparece como retry — melhor que
   preço perdido silenciosamente, mas o ideal é eliminar a colisão.

**Ação:**

- Adicionar `->name(...)->withoutOverlapping()` aos dois `Schedule::call()`
(o mutex do Laravel exige `->name()` para closures, já que não há um
comando com string própria para derivar a chave):
  ```php
  Schedule::call(fn () => app(UpdateOffersUseCase::class)->execute(OffersUpdateMode::WeAreLowest))
      ->name('update-offers-we-are-lowest')
      ->withoutOverlapping()
      ->cron('*/5 * * * *')->timezone('America/Sao_Paulo')->environments('production');

  Schedule::call(fn () => app(UpdateOffersUseCase::class)->execute(OffersUpdateMode::WeAreNotLowest))
      ->name('update-offers-we-are-not-lowest')
      ->withoutOverlapping()
      ->cron('35 * * * *')->timezone('America/Sao_Paulo')->environments('production');
  ```
- Trocar o cron do modo `WeAreNotLowest` de `5 * * * *` para um minuto que
não coincida com nenhum múltiplo de 5 do outro job (ex: `35 * * * *`) —
elimina a colisão estrutural entre os dois modos.

**Origem:** code-review do fix de action-lock em `GamivoApiService` (2026-07-20).

---

## Polimentos adiados do domínio Financial

**Onde:** `app/UseCases/Financial/`, `tests/Feature/UseCases/Financial/`, `tests/Feature/Services/Financial/`.

Itens levantados no code-review dos tickets 1–3 do `FinancialMonth` e conscientemente adiados —
nenhum bloqueia funcionalidade. Reavaliar **durante o redesenho** (tickets `R1`–`R8` acima),
porque parte deles morre junto com o código que os originou:

- [ ] **`?string $occurredAt`** nos UseCases de lançamento — `Money` é VO, datas não.
  Trocar por `\DateTimeInterface`/`CarbonImmutable` evita bug de formato na fronteira.
- [ ] **Float strict-equality** (`$balance === 0.0`) ao decidir se cria movimento de abertura
  no carry-forward. Funciona hoje porque `Money::toReais()` devolve float quantizado ao
  centavo, mas quebra silenciosamente se um valor não-quantizado passar por ali.
  Preferir `abs($balance) < 0.005` ou devolver `Money` do service.
- [ ] **Nomes de fixture genéricos** — o Pest promove `function draftMonth()` e
  `seedClosableDraft()` para o namespace global; `draftMonth` é genérico o bastante para
  colidir com testes futuros. Mover para `tests/Support/` ou renomear com prefixo do domínio.
- [ ] **Duplicação da criação de movimentos** — `movements()->create([...])` repetido em
  `CreateDraftFinancialMonthUseCase` e `CloseMonthUseCase`. Com os UseCases novos de
  transferência/distribuição o padrão passa de 2 para 4+ sítios, cruzando o limiar do
  `CLAUDE.md` para extrair um helper.

**Ação:** endereçar dentro dos tickets do redesenho, não como passada separada.

**Origem:** code-review dos tickets 1–3 do FinancialMonth (2026-08-01), eixo Standards.