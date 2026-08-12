# IMPROVEMENTS — pendências do sistema

Fonte única de tudo que ainda deve ser feito no sistema: roadmap, features
planejadas, melhorias de qualidade e dívida técnica de code-review. Centraliza o
que antes estava espalhado no `CLAUDE.md`, `docs/GAMIVO.md`, `docs/PRODUCT.md` e
comentários de código. Cada entrada referencia onde mexer (**Onde**), o que fazer
(**Ação**) e de onde veio (**Origem**).

Ordem: roadmap/qualidade/features primeiro, dívida técnica de code-review no fim.

---

## Refatoração de camadas — orquestração fora de Use Case (fatias 2 a 4)

**Onde:** `routes/console.php`, `app/Services/`, `app/Http/Controllers/`, `app/UseCases/`.

Auditoria arquitetural de 2026-08-10 mapeou 16 pontos onde orquestração vive em
Controller ou Service. O critério de promoção ficou definido assim: **um UseCase é
uma operação de escrita disparada de fora (HTTP, cron, CLI) que orquestra passos** —
sem contar colaboradores. Leitura nunca vira UseCase (vai para Repository/Service,
com a whitelist de filtros num FormRequest), porque a separação escrita/leitura é o
que prepara o CQRS pretendido; statement único sobre um modelo só fica onde está.

Fatias 0 (whitelist de filtros em `POST /keys/search`), 1 (crons) e 2 (controllers
com orquestração) já foram entregues; o critério está registrado em
[`docs/adr/0007`](adr/0007-usecase-promotion-criteria.md). Falta:

- [ ] **Fatia 3 — CRUD e atomicidade.** `BundleController` (`store`, `addGames`,
  `removeGames`, `update`) → `BundleService`; `removeGames` está sem FormRequest.
  Os 5 `destroyArray` (`Key`, `Game`, `Asset`, `Fee`, `AuthorizedUsers`) viram
  `Service::deleteMany()` transacional: hoje **4 dos 5 não têm transação** e, ao
  falhar no meio do loop, deletam parcialmente e ainda respondem erro.
- [ ] **Fatia 4 — árvore de `Services/`.** `APIService` → `External/GgDealsApiService`
  (é cliente da GG.deals); `BundleService` → `Services/Bundles/`; `FinancialService`
  → `Services/Sales/SalesDashboardService` (**não** `Services/Financial/`, que é o
  livro-caixa em R$ — juntar os dois apaga a distinção que o `CONTEXT.md` mantém);
  Esta é a "auditoria da árvore de `Services/`" que o `CLAUDE.md` já registrava.
  (`AssetService::getAssetsCurrency` já virou `CurrencyConversionService::convertAll()`
  na Fatia 1, junto com a morte do `AssetService`.)

Ficam **deliberadamente** como estão: `FeeController` e `AuthorizedUsersController`
falando Eloquent direto (CRUD trivial de um modelo) e `BundleController::index`.

**Origem:** sessão de `/grill-with-docs` sobre responsabilidades de camada (2026-08-10).

---

## Dashboard de gastos por categoria (FinancialMonth)

**Onde:** provavelmente uma tela nova sob `/financial-months` (ou uma aba dela), consumindo `FinancialMovement.expense_category`/`income_category`.

Depois que os lançamentos de `expense`/`income` passarem a carregar `ExpenseCategory`/`IncomeCategory` (ver `CONTEXT.md`), a agregação por categoria (quanto foi gasto em Impostos vs. Assinaturas, por exemplo) fica disponível para uma tela de análise — hoje `FinancialMonths.vue` só lista o extrato lançamento a lançamento, sem nenhuma visão agregada.

**Ação:** desenhar em sessão própria de `/grill-with-docs` — granularidade temporal (por mês? por período arbitrário?), quais métricas, layout. Só faz sentido depois de haver dados reais categorizados em produção.

**Origem:** sessão de `/grill-with-docs` sobre categorização de movimentos financeiros (2026-08-03) — motivação original do pedido, escopo deliberadamente separado da categorização em si.

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

**Onde:** fluxo de expiração (scheduler / `AlertExpiringKeysUseCase`).

Quando faltam 30 dias, o sistema já envia alerta por e-mail e a `MinimumMarginPolicy` rebaixa o `min_api` ao piso. Falta: no dia em que a key expira, remover a oferta da Gamivo e avisar por e-mail.

**Origem:** `docs/PRODUCT.md`.

---

## Processo para estoque morto (keys com mercado abaixo do custo de compra)

**Onde:** provavelmente um novo UseCase em `app/UseCases/Marketplaces/Gamivo/` (ou `app/UseCases/Keys/`, já que o problema também afeta keys ainda não listadas) + alguma superfície pra revisão manual (relatório recorrente, tela ou export). `app/Domain/Pricing/MinimumMarginPolicy.php` não é o lugar — ver "Origem" abaixo sobre por que isso não é ajuste de margem.

Rodando `gamivo:min-api-floor-report` e `gamivo:unlisted-min-api-report` (ver `docs/GAMIVO.md`) contra um snapshot de produção (2026-08-09), boa parte das keys "travadas no `min_api`" não é caso de margem conservadora demais — é o **preço de mercado atual abaixo do próprio custo de compra** (`individual_cost`). Nesses casos, baixar a margem exigida não resolve nada: o piso já está protegendo contra uma venda no prejuízo.

Volume identificado nesse snapshot:
- **45 keys nunca listadas** (de 162 elegíveis para auto-sell) com mercado abaixo do custo — €56,72 de custo parado, gerando **zero receita** porque nem chegam a ser listadas pelo `AutoSellUseCase`.
- **5 ofertas já listadas** no mesmo caso (Descenders, Until Then ×2, Suicide Guy, The Darkness II) — ficam presas no `min_api` (que também não cobre o mercado), sem vender.

Hoje não existe processo para identificar ou decidir o que fazer com esse grupo — as keys só ficam invisíveis, sem alerta, acumulando.

**Ação (possíveis soluções, a decidir):**
- [ ] Job/relatório recorrente que roda a mesma comparação (mercado vs. `individual_cost`) e persiste o resultado, em vez de exigir rodar os comandos manualmente toda vez — os dois comandos atuais chamam a API Gamivo (read-only) e não têm agendamento
- [ ] Definir um limiar de tempo "underwater" (ex: mercado abaixo do custo por ≥ N meses) que dispara alerta por e-mail, no mesmo padrão do `AlertExpiringKeysUseCase`
- [ ] Decidir a política de liquidação: vender abaixo do custo pra liberar capital (após X tempo) vs. segurar indefinidamente — provavelmente uma decisão de negócio, não só técnica
- [ ] Avaliar se o processo de compra deveria checar tendência de preço recente antes de fechar a trade (o sistema já verifica giveaways via `gamerpower.com/api-read`, ver `docs/PRODUCT.md` — pode ser o mesmo tipo de checagem preventiva, olhando queda de preço em vez de giveaway)

**Origem:** sessão de diagnóstico de `min_api` (2026-08-09) — a investigação original era sobre `MinimumMarginPolicy::DEFAULT_MARGIN` (ajustado de 60% para 50%, ver `MinimumMarginPolicyTest.php`), mas separar os casos "mercado abaixo do custo" dos casos "margem alta demais" revelou que boa parte do volume travado é estoque morto, não ajuste de tier.

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

## `ResolveSteamIdsUseCase` — "não encontrado" e "sem dados" viram o mesmo carimbo

**Onde:** `app/UseCases/Games/ResolveSteamIdsUseCase.php` (marcação de `games.steamcharts_searched_at`).

Hoje o desfecho da busca é binário: **todo** jogo enviado ao `price_researcher` recebe `steamcharts_searched_at = now()` quando a requisição volta bem-sucedida, e isso o remove permanentemente da fila (`whereNull('steamcharts_searched_at')`). O carimbo, porém, cobre desfechos que não são equivalentes:

| Desfecho | Hoje | Deveria |
|---|---|---|
| Jogo veio com `id_steam` | carimbado, `steam_id` gravado | igual — resolvido |
| Jogo veio sem `id_steam` — SteamCharts não conhece o título | carimbado, nunca reprocessado | igual — conclusivo, procurar de novo não muda nada |
| Jogo veio, existe no SteamCharts, mas **sem dados** (sem `id_steam` utilizável / sem série de players) | carimbado junto com o caso acima | separar: existe, então o retorno não é conclusivo sobre o `steam_id` |
| Jogo **ausente** da resposta (lote parcial, timeout no meio da varredura) | carimbado mesmo assim — o `update` usa os ids enviados, não os recebidos | não carimbar: nada foi concluído sobre ele |

As duas últimas linhas aposentam jogos que ainda seriam resolvíveis, e o efeito é silencioso e permanente — o jogo simplesmente nunca mais aparece na fila, sem log nem alerta. Como `steam_id` é pré-requisito do `UpdatePopularityUseCase`, o jogo também fica sem popularidade para sempre.

**Ação:** trocar o carimbo único por um desfecho por jogo. Carimbar apenas o que a resposta declarou conclusivamente (encontrado, ou inexistente no SteamCharts); deixar o que não voltou — ou voltou sem dados — elegível para a próxima rodada. Exige acertar antes o contrato da resposta com o `price_researcher`: hoje `data.games` não distingue "não existe" de "não consegui olhar". Avaliar se a distinção cabe numa coluna de status em `games` ou num contador de tentativas, para que "sem dados" não vire loop infinito de retentativa.

**Origem:** teste manual do UseCase após a extração do `GameService` (2026-08-11).

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

## Polimentos adiados do domínio Financial

**Onde:** `app/UseCases/Financial/`, `tests/Feature/UseCases/Financial/`, `tests/Feature/Services/Financial/`.

O que sobrou do code-review do `FinancialMonth` depois do redesenho para o fluxo manual.
Nenhum bloqueia funcionalidade:

- [ ] **`?string $occurredAt`** nos DTOs de lançamento (`app/UseCases/Financial/DTO/`) —
  `Money` é VO, datas não. Trocar por `\DateTimeInterface`/`CarbonImmutable` evita bug de
  formato na fronteira.
- [ ] **Float strict-equality** (`$balance === 0.0`) em três sítios: ao decidir se cria
  movimento de abertura no `CreateDraftFinancialMonthUseCase` e no carry-forward do
  `CloseMonthUseCase`, e ao pular a devolução de verba zerada. Funciona hoje porque
  `Money::toReais()` devolve float quantizado ao centavo, mas quebra silenciosamente se um
  valor não-quantizado passar por ali. Preferir `abs($balance) < 0.005` ou devolver `Money`
  do service.

Resolvidos pelo redesenho: a duplicação de `movements()->create([...])` (extraída para
`Services/Financial/MovementRecorder.php`) e os nomes de fixture genéricos (`draftMonth`/
`seedClosableDraft` deram lugar a `tests/Support/FinancialMonthFactory`).

**Origem:** code-review dos tickets 1–3 do FinancialMonth (2026-08-01), eixo Standards.