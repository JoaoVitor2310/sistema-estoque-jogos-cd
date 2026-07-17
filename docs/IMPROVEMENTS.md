# IMPROVEMENTS — dívidas técnicas identificadas em code-review

Itens de baixa/média severidade encontrados durante revisões de código, deixados
para tratamento futuro por não bloquearem a funcionalidade em questão. Cada
entrada referencia a origem (revisão que identificou) e o que precisa ser feito.

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
`games.*` entre esses FormRequests, ou um Value Object de validação.

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
