# IMPROVEMENTS — pendências do sistema

Fonte única de tudo que ainda deve ser feito no sistema: roadmap, features
planejadas, melhorias de qualidade e dívida técnica de code-review. Centraliza o
que antes estava espalhado no `CLAUDE.md`, `docs/GAMIVO.md`, `docs/PRODUCT.md` e
comentários de código. Cada entrada referencia onde mexer (**Onde**), o que fazer
(**Ação**) e de onde veio (**Origem**).

Ordem: roadmap/qualidade/features primeiro, dívida técnica de code-review no fim.

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

## Remover `supplier_url` de `keys`

**Onde:** `app/Models/Key.php`, `app/Http/Controllers/Keys/KeyController.php`, `app/UseCases/Keys/RegisterKeyUseCase.php`, `app/UseCases/Keys/ImportKeysFromXlsxUseCase.php`, `app/Domain/Import/ImportRowValidator.php`.

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

## Regra de validação `games.*.gamivo_id` duplicada em 5 FormRequests

**Onde:**

- `app/Http/Requests/ImportTradeKeysRequest.php`
- `app/Http/Requests/ProspectSupplierRequest.php`
- `app/Http/Requests/StoreListTradeRequest.php`
- `app/Http/Requests/GameRequestArray.php` (pré-existente)
- `app/Http/Requests/StoreGameRequestArray.php` (pré-existente)

`'games.*.gamivo_id' => ['nullable', 'string']` está copiada identicamente em
cinco classes. Um endurecimento futuro da regra (ex: exigir apenas dígitos)
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
um terceiro ponto de entrada (ex: importação XLSX) precisar do mesmo
mapeamento no futuro, momento em que a extração passa a se justificar.

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