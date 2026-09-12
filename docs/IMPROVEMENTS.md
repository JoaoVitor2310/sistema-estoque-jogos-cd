# IMPROVEMENTS — pendências do sistema

Fonte única de tudo que ainda deve ser feito no sistema: roadmap, features
planejadas, melhorias de qualidade e dívida técnica de code-review. Centraliza o
que antes estava espalhado no `CLAUDE.md`, `docs/GAMIVO.md`, `docs/PRODUCT.md` e
comentários de código. Cada entrada referencia onde mexer (**Onde**), o que fazer
(**Ação**) e de onde veio (**Origem**).

Ordem: roadmap/qualidade/features primeiro, dívida técnica de code-review no fim.

---

## `simulated_income` negativo — verificar se é legítimo

**Onde:** `app/Domain/Pricing/IncomeCalculator.php`, coluna `keys.simulated_income`.

**Ação:** confirmar com o negócio se income negativo deve existir. Ele é plausível — quando o preço
de mercado é baixo o bastante, as taxas da Gamivo superam o valor da venda, e o número diz
literalmente "vender isso dá prejuízo" — mas convém decidir se o certo é gravar o valor negativo,
zerar, ou marcar a key como inviável. Hoje 8 keys estão assim, 5 delas já vendidas.

**Origem:** encontrado em 2026-09-01 ao limpar os `individual_cost` negativos. É a origem provável
deles: o rateio do custo multiplica pelo income do jogo. Diferente do custo, o income negativo
**não** foi saneado — ele pode ser um fato do mercado, não um dado inválido.

---

## Reembolso não tem registro próprio

**Onde:** tabela `keys` (`sold_price`, `sale_profit`), `app/Console/Commands/BackfillSoldPricesCommand.php`.

**Ação:** dar ao reembolso um registro próprio — uma coluna de status da venda (`sold`,
`refunded`) ou um lançamento na `financial_movements` — em vez de codificá-lo sobrescrevendo
`sold_price`/`sale_profit` com o resultado financeiro final. Hoje o desfecho de um reembolso mora
nos mesmos dois campos de uma venda normal, então o sistema não sabe **quantos** reembolsos houve,
nem quanto se perdeu em taxa de €1, nem qual fornecedor devolveu o dinheiro — dados que só existem
na memória de quem lançou.

O custo prático já apareceu: o backfill precisa preservar esses lançamentos e só consegue
reconhecê-los por assinatura — `sold_price ≤ 0`, ou `sold_price = individual_cost` com
`sale_profit` exatamente zero. Funciona, mas é leitura de rastro: um ajuste manual que não siga
nenhum dos dois padrões passa despercebido e precisa de `--except` na mão. Com um marcador
explícito, o comando não precisaria adivinhar nada.

**Origem:** conversa de 2026-08-27 durante a validação do backfill, ao investigar uma key com
`sold_price = −1,00`. Convenção documentada no verbete "Venda reembolsada" em
[`CONTEXT.md`](../CONTEXT.md).

---

## Observabilidade do rateio igual na baixa de vendas

**Onde:** `app/UseCases/Marketplaces/Gamivo/UpdateSoldOffersUseCase.php`,
`app/Domain/Enums/OrderPayoutAttribution.php`.

**Ação:** hoje um pedido que cai em `partially_matched`/`equal_split` só aparece como
contagem em `orders_by_attribution` e como `Log::warning` no `schedulers.log` — para
saber *quais* pedidos foram chutados é preciso ler o log linha a linha. Levar a
atribuição para um lugar consultável: coluna na `keys` (ou tabela de auditoria da
baixa) gravada junto com `sold_price`, e um alerta quando a proporção de pedidos não
`matched` passar de um limiar numa passada. Sem isso, um `gamivo_id` faltando numa key
degrada o valor gravado em silêncio e ninguém percebe até conferir o extrato.

**Origem:** code-review da correção do rateio por linha (2026-08-27). O comportamento do
fallback foi mantido de propósito — registrar a receita vale mais que a precisão por
key; o que falta é enxergar quando ele age. Ver a tabela de casos em
[`docs/GAMIVO.md`](GAMIVO.md#baixa-de-vendas-uma-linha-de-histórico-por-oferta).

---

## Segurança das keys — pendências da revisão de 2026-08-19

O que sobrou da revisão feita quando a entrega (`/deliveries/{uuid}`) passou a levar gente de fora
ao domínio. O que já foi resolvido, e o que foi avaliado e deliberadamente mantido, está em
[`docs/agents/security-and-guardrails.md`](agents/security-and-guardrails.md) — aqui só fica o que
ainda não foi feito.

### 1. Backup existe só dentro da VPS

**Onde:** `backup.sh`, cron da VPS.

O envio para o Google Drive saiu em 2026-08-19: o `pg_dump` é texto puro e carrega todas as
`keys.key_code` em claro, então a segurança da cópia era a da conta Google. O que sobrou é um
backup diário em `/var/www/sistema-estoque-jogos-cd/backups`, na mesma máquina do banco — protege
contra `DROP TABLE` acidental e contra corrupção, e não protege contra perder a VPS.

**Ação:** voltar a ter cópia fora da máquina, **encriptada na origem**. `gpg --symmetric` (ou
`age`) antes do envio, com a chave guardada fora da VPS, ou `rclone crypt` no remote — a diferença
é que no primeiro o arquivo já sai ilegível do disco. Testar a restauração uma vez: backup nunca
restaurado é hipótese, não backup. Aproveitar para tirar `POSTGRES_DB`/`POSTGRES_USER` cravados no
script e lê-los do `.env`.

### 2. `/register` aberto e `can-edit` casando só por e-mail

**Onde:** `routes/auth.php`, `app/Http/Controllers/Auth/RegisteredUserController.php`,
`app/Models/User.php` (o `MustVerifyEmail` está comentado), gate `can-edit` em
`app/Providers/AppServiceProvider.php`.

Metade já foi resolvida: as páginas da equipe passaram de `RequireAuth` para `RequireTeam` e uma
conta de fora não alcança mais nenhuma delas (`tests/Feature/Security/RegisteredUserAccessTest.php`).
O que sobra é a porta de entrada em si: qualquer pessoa cria conta, sem convite e sem verificação
de e-mail, e o `can-edit` casa `authorized_users.email` com `users.email` **sem nenhuma prova de
posse do endereço**. Não é explorável hoje porque `users.email` é único e as contas da equipe já
existem; passa a ser no dia em que um e-mail entrar na lista de autorizados antes de a pessoa criar
a conta — que é exatamente o que acontece ao adicionar alguém novo.

**Ação:** fechar o registro (remover a rota; acesso novo nasce de um convite do admin) ou, no
mínimo, ligar `MustVerifyEmail` e exigir e-mail verificado dentro do gate `can-edit`.

### 3. CSRF desligado globalmente

**Onde:** `bootstrap/app.php` → `validateCsrfTokens(except: ['*'])`; a entrega religa por
`ValidateDeliveryCsrfToken`.

Toda rota da equipe aceita POST sem token. O que segura hoje é o `SESSION_SAME_SITE=lax`, que é
configuração de ambiente — a proteção depende de um `.env`, não do código.

**Ação:** inverter a exceção (verificar por padrão, isentar só os webhooks com `VerifySecret`, que
autenticam por Bearer). O axios do frontend já manda `X-XSRF-TOKEN`.

### 4. `/register` sem rate limit

**Onde:** `routes/auth.php`.

O catálogo fechou em 2026-08-19, então o que sobrou aberto a visitante é `/login` (com o throttle
de 5 tentativas do Breeze), a entrega (com os dois limitadores próprios) e `/register`, que não tem
nenhum. Enquanto a rota existir, dá para criar contas em série — e cada conta é uma sessão válida
esperando que uma página nova nasça sem middleware.

**Ação:** `throttle` na rota enquanto ela existir. Se o registro for fechado (item 2), este item
morre junto.

### 5. Verificações de produção

- `APP_DEBUG=false` — com `true`, uma exceção mostra a SQL com bindings, e algumas dessas queries
  carregam `key_code`.
- `SESSION_SECURE_COOKIE=true` — a chave passou a existir no `.env.example`; falta garantir o valor
  em produção.
- `.env` de produção sem nenhum valor herdado do `.env.example` — os placeholders de lá foram
  esvaziados em 2026-08-19, mas quem copiou antes disso levou os valores junto.
- nginx sem HSTS e sem `server_tokens off` (`docker/nginx/default.conf`).
- `AuthController::logged` devolve `response()->json($user)` e o `$hidden` do `User` não esconde
  `google_token`/`google_refresh_token`.
- `config/services.php` aponta o redirect do Google para `http://localhost:8000` cravado, ignorando
  `GOOGLE_REDIRECT_URI`; e `services.sistema-estoque` (`THIS_URL`/`DEV_THIS_URL`) não tem nenhum
  consumidor no código.

**Origem:** revisão de segurança pedida em 2026-08-19, motivada pela entrega levar terceiros ao
domínio.

### 6. `entrypoint.sh` instala dependências de dev em produção

O entrypoint roda `composer install --no-interaction` e `npm install` **antes** de olhar o `APP_ENV`,
então a VPS carrega Pest, PHPStan e todo o `devDependencies` do npm. Em produção o certo seria
`composer install --no-dev --optimize-autoloader`, e o `npm run build` do ramo de produção é
redundante com o build que o CI já faz e envia por `scp`.

Só não foi mexido junto com a correção do `APP_ENV` (2026-08-20) porque muda o que existe dentro do
container em produção e merece ser feito com o site parado.

**Origem:** incidente do `public/hot` em 2026-08-20 — ver
[`docs/agents/deploy.md`](agents/deploy.md).

---

## Resiliência da VPS — pendências do incidente de 2026-08-24

A VPS ficou inacessível por SSH com CPU em 100% e o OOM killer em loop. **Duas causas
independentes se somando**, nenhuma delas o bot de trading do sócio (MetaTrader sob Wine, que era
o suspeito inicial e estava normal):

| Causa | Efeito | Estado |
|---|---|---|
| `app-cd` rodando a imagem anterior à correção do `APP_ENV` de 20/08, em modo `local` | Vite dev server em polling sobre `vendor/` — um core preso por semanas | **Corrigido** neste repo (ver abaixo) |
| `price_researcher` vazando um Chromium por scraping falho de AllKeyShop | 7,4 GB de 7,9 GB consumidos em 16h, 211 zumbis, OOM em loop | Contido por `mem_limit`/`pids_limit` no compose dele; causa raiz é o item 6 da Alta Prioridade do backlog do `price-cd` |

O que já foi feito neste repo: o entrypoint passou a resolver o ambiente pelo `.env`
(`docker/resolve-app-env.sh`, guardado por `tests/Feature/EnvironmentTest.php`), o ramo de
produção apaga `public/hot`, e o watcher do Vite deixou de vigiar `vendor/` e `storage/`.

Na máquina, a VPS ganhou **4 GB de swap** (`/swapfile`, persistido no `/etc/fstab`) — ela rodava com
zero, e era isso que fazia a pressão de memória ir direto para o OOM killer em vez de degradar,
transformando "lenta" em "inacessível por SSH". O swap não conserta vazamento: compra o tempo
necessário para entrar na máquina e intervir.

O gatilho da rajada é o agendamento **deste** sistema — `ResolveSteamIds` às 06:00 e
`UpdatePopularity` às 07:00 (`routes/console.php`) —, mas o defeito é do `price_researcher` e a
pendência mora no backlog dele, não aqui.

O que continua pendente neste repo:

### 7. VPS sem atualização há 221 dias

**Onde:** infraestrutura, fora de qualquer repositório.

165 pacotes pendentes, 29 correções de segurança, ESM desligado e `System restart required` — há
atualização de kernel esperando desde antes do uptime atual, então boa parte só entra em vigor com
o reboot.

**Ação:** `apt update && apt upgrade` e reiniciar numa janela combinada. Todos os serviços têm
política de restart, então voltam sozinhos; conferir mesmo assim com `docker ps` depois do boot, e
que `systemctl is-enabled docker` responde `enabled`.

**Origem:** incidente de 2026-08-24.


## Trilha de eventos da entrega de trade

**Onde:** domínio da entrega (`/deliveries/{uuid}`), ver [`docs/adr/0008`](adr/0008-supplier-fills-trade-through-tokenised-link.md).

A entrega grava direto na trade, sem registrar quem escreveu. Quando um supplier disser
"eu mandei essa key" e a trade não tiver, não há como responder — e tentativas de adivinhar
token passam despercebidas (o rate limit bloqueia, mas não avisa ninguém).

**Ação:** tabela enxuta de eventos com `trade_id`, tipo (`token_failed`, `token_ok`,
`saved`, `delivered`), IP, user agent e timestamp. **Sem valor de campo nenhum** —
copiar os `key_code` para uma segunda tabela é criar mais um lugar de onde eles vazam;
o valor corrente já está na trade.

**Origem:** sessão de `/grill-with-docs` sobre entrega de trade pelo supplier (2026-08-13) —
adiado deliberadamente; a conferência humana antes do import é a mitigação atual.

---

## Teste automatizado de frontend

**Onde:** `resources/js/`, `package.json` (hoje só `dev` e `build`), `.github/workflows/ci.yml` (três jobs, todos PHP).

O frontend não tem runner nenhum: toda mudança de `.vue` é verificada por `npm run build`, que
só prova que compila. Isso bastava enquanto o Vue era desenho, mas ele passou a carregar
**regra**: `Delivery.vue` monta a máscara de `mm/dd/aaaa`, decide o que é validade incompleta e
desabilita o envio sem `tf2_qty`; `Trades.vue` tem `canImport()`, que espelha
`ImportReadinessPolicy`. Nenhuma dessas linhas tem teste, e a falha típica delas é **silenciosa**:
um campo que o servidor descarta sem erro (foi o caso de `02012026` virar validade nenhuma com a
página dizendo "Saved") passa em toda a suíte Pest.

**Ação:** duas camadas, nesta ordem de retorno.

1. **Vitest + `@vue/test-utils` + jsdom** para a lógica. O pré-requisito é extrair as funções puras
   dos SFC para módulos próprios (`resources/js/domain/`), importáveis sem montar componente:
   máscara e validação de data, `hasBadExpiry`, `tf2Missing`, `canImport`. Testar montando o
   componente também funciona, mas amarra o teste ao markup — o teste quebra ao mexer numa classe
   CSS. A extração dá de brinde um lugar único para anotar que aquela regra é o par TS de uma
   classe de Domain PHP.
2. **Playwright** para o fluxo da entrega ponta a ponta (token → preencher → enviar → leitura),
   que é onde o backend não enxerga: a página é a única superfície com usuário de fora, e o que
   falha nela falha sem exceção nenhuma no servidor.

Fechar com um `npm run test` e um quarto job no `ci.yml`, ao lado de Pint, PHPStan e Pest — teste
que não roda no CI vira teste que ninguém roda.

**Origem:** revisão da tela de entrega (2026-08-19) — máscara de validade, larguras de coluna e
selo de autosave entraram sem teste, verificados só pelo build.

---

## Internacionalização (i18n) das telas

**Onde:** `resources/js/Pages/`, `lang/`.

Hoje todo texto visível é português cravado no template, porque o único usuário era a
equipe. A página de entrega de trade (`/deliveries/{uuid}`) quebra essa premissa: o
usuário é o supplier, um trader estrangeiro, e a tela nasce em inglês — a convenção
passa a ser **português nas telas internas, inglês nas telas de terceiros**.

**Ação:** adotar um mecanismo de tradução (`lang/` do Laravel exposto ao Inertia, ou
`vue-i18n`) e extrair as strings cravadas, começando pelas telas de terceiros. Enquanto
não existir, telas de terceiros seguem em inglês literal.

**Origem:** sessão de `/grill-with-docs` sobre entrega de trade pelo supplier (2026-08-13).

---

## Canonizar as regiões de key

**Onde:** `games.region`, `keys.region`, `suppliers.region`; candidato a enum em `app/Domain/Enums/`.

`region` é `string` livre em todas as tabelas, mas funciona como **chave de busca**:
`GameService::getIdGamivo()`/`getSteamId()` casam `games.name` + `games.region` para
resolver `gamivo_id` e `steam_id` (`app/Services/Games/GameService.php:23`). Um valor
divergente (`Europe` em vez de `EU`) não falha o import — cria um Game órfão sem
`gamivo_id`, e o erro só aparece semanas depois, quando a oferta não precifica.

Hoje o risco é baixo porque só a equipe digita. A página de entrega abre o campo para o
supplier (decisão: texto livre, com conferência humana antes do import), o que torna
divergência de grafia esperada em vez de excepcional.

**Ação:** levantar os valores distintos em produção, definir a lista canônica num enum
`KeyRegion`, migrar os dados e trocar os inputs por select. Não bloqueia a entrega de
trade — a conferência antes do import é a mitigação atual.

**Um efeito colateral novo, desde que mudar a região apaga o `gamivo_id` da linha
(`GamivoIdentity`):** o supplier escrevendo `Europe` onde a equipe escreveu `EU` passa a
apagar um id que era válido. O custo é limitado — o import refaz o lookup —, mas com
grafia divergente esse lookup também não casa, e a key entra sem id. É mais um argumento
para a canonização: enquanto `region` for texto livre, grafia é indistinguível de troca
de produto.

**O campo da entrega fica de fora do select.** O supplier costuma saber menos que a
grafia canônica e mais que o rótulo — em que país a key não funciona, se veio de uma
loja regional — e a página pede isso explicitamente ("anything you know"). Fechar o
input dele em opções jogaria fora justamente a informação que ajuda a equipe a decidir
a região certa. O valor canônico é o que a equipe grava na conferência, não o que o
supplier digita.

**Origem:** sessão de `/grill-with-docs` sobre entrega de trade pelo supplier (2026-08-13).

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

**Onde:** `database/migrations/` (nova migration), `app/Models/Key.php`, `app/Models/Trade.php`, `app/Domain/Pricing/ProfitCalculator.php` (`individualCost`), `app/UseCases/Keys/RegisterKeyUseCase.php`, `app/Services/Sales/SalesDashboardService.php` (`getTf2Spent`).

`tf2_quantity` é o total de TF2 keys pago pela **trade**, não por cada key — hoje está duplicado em toda key do lote (mesmo valor repetido) e não deveria ser editável no nível da key. O lugar correto é `trades.tf2_qty` (que já existe). O rateio de `individual_cost` passaria a ler a quantidade da trade, e o `getTf2Spent` deixaria de precisar deduplicar por `(total_paid, acquired_at)`.

**Ação:** migrar o valor para `trades`, ajustar o cálculo de rateio para ler da trade, e remover a coluna de `keys` (Expand-Contract). Enquanto não migra, `tf2_quantity` **não** deve ser editável na tela de keys.

**Origem:** decisão ao gatilhar o recálculo do `UpdateKeyUseCase` por `market_price` (2026-07-24).

---

## Remover `supplier_url` de `keys`

**Onde:** `app/Models/Key.php`, `app/Http/Resources/KeyResource.php`, `app/UseCases/Keys/RegisterKeyUseCase.php`, `app/UseCases/Keys/UpdateKeyUseCase.php`, `app/Http/Requests/StoreGameRequest.php`.

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

## O teto de vendedor único se ancora num preço de compra, não de hoje

**Onde:** `app/Domain/Pricing/MinMaxPriceCalculator::soleSellerPrice()`, chamado por `AutoSellUseCase` e `UpdateOffersUseCase`.

`market_price` é, **por definição**, o preço pesquisado no dia da trade: é ele que rateia o `individual_cost` do lote e fixa `simulated_income` e `purchase_profit`. Não deve ser atualizado — mexer nele reescreve a contabilidade da compra (ver [`docs/adr/0004`](adr/0004-recalculate-trade-on-key-edit.md)).

O problema é que o teto de vendedor único o usa como referência de mercado **atual**, que não é o que ele significa. Uma key parada num jogo que valorizou fica anunciada por um preço de meses atrás enquanto não aparecer concorrente — e o de sempre também vale: um jogo que desvalorizou fica caro.

**Ação:** se a defasagem incomodar, **não** atualizar `market_price`. Acrescentar uma coluna própria de preço corrente (`current_market_price`, nulável), alimentada por um scheduler via `price_researcher` (ver `docs/PRICE_RESEARCHER.md`) e lida **só** pela precificação, com fallback para `market_price` quando estiver vazia. As fórmulas de custo e lucro continuam olhando exclusivamente para `market_price`.

**Origem:** decisão de escopo ao implementar o teto de vendedor único (2026-08-21) — a defasagem foi conscientemente aceita para não acoplar o fluxo da Gamivo a uma integração externa.

---

## `UpdateOffersUseCase` reenvia o mesmo preço quando há concorrente

**Onde:** `app/UseCases/Marketplaces/Gamivo/UpdateOffersUseCase.php::processProduct`.

`ComparisonAlgorithm` nunca compara o alvo com o preço que já praticamos: sempre devolve `updatePrice`, e o `processProduct` sempre chama `updateOffer`. Rodando a cada minuto, isso significa `PUT` redundante sempre que o alvo não se moveu. O caminho **sem** concorrente já tem guarda (`priceAsSoleSeller` só envia se o `seller_price` divergir), porque ali o alvo é constante e o desperdício seria permanente; o caminho com concorrente ficou de fora para não mexer no fluxo quente na mesma entrega.

**Ação:** medir quantos `PUT` por dia são no-op e, se compensar, estender a mesma guarda ao caminho com concorrente.

**Origem:** decisão de escopo ao implementar o teto de vendedor único.

---

## Regime de preço de vendedor único é invisível na tela

**Onde:** `resources/js/Pages/Keys.vue` (colunas Min. API / Max. API), `app/Http/Resources/KeyResource.php`, `app/UseCases/Marketplaces/Gamivo/UpdateOffersUseCase.php::priceAsSoleSeller`.

Sem concorrente, a oferta é precificada por `market_price × 1,10` e o `max_api` continua no banco valendo o teto de valorização — de propósito, ver [`docs/adr/0010`](adr/0010-sole-seller-ceiling-computed-not-persisted.md). O efeito colateral é que a tela de Keys mostra um Max. API de €24 numa key que está anunciada a €10, sem nada que explique a diferença: o sistema nem exibe o preço praticado, que só existe no painel da Gamivo. Hoje a única pista é o canal de log `schedulers`, na chave `pricing`.

**Ação:** se incomodar na prática, gravar um marcador de **observação** (não de regra) no caminho sem concorrente — algo como `keys.sole_seller_at`, atualizado a cada passada que não encontra concorrente — e exibir um selo ao lado do Max. API com o teto de mercado vigente. Marcador de observação não contamina a semântica do `max_api` e se cura sozinho quando o concorrente volta. Custo: migration, uma escrita a mais no fluxo que roda por minuto, campo no `KeyResource` e coluna no Vue.

**Origem:** discussão ao implementar o teto de vendedor único (2026-08-21) — avaliado e adiado, com a documentação assumindo o papel de explicar a divergência.

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

## `CreateTradeUseCase` — parâmetros sem chamador

**Onde:** `app/UseCases/Trades/CreateTradeUseCase.php`.

`execute()` aceita `title`, `supplierUrl`, `date` e `tf2Qty`, mas o único chamador de produção é `TradeController::store`, que passa `[]`. O commit `36b6d87` ("feat: remove area to paste trade", #48) removeu o formulário de criação do `Trades.vue`; desde então a trade nasce em branco e todo campo entra pelo PATCH (`UpdateTradeRequest` → `UpdateTradeUseCase`, que tem a mesma assinatura). Os quatro campos só são exercitados pelos próprios testes — junto com eles, `parseDate()` e o ramo `supplierService->upsertByUrl()`.

**Ação:** confirmar que nenhum fluxo manda esses campos na criação e reduzir `execute()` a criar a trade em branco (linha vazia + credencial de entrega), removendo `parseDate()` e a dependência de `SupplierService` se ela ficar sem uso. Os testes de `tf2_qty` em `tests/Feature/UseCases/Trades/CreateTradeUseCaseTest.php` saem junto — a cobertura equivalente já existe em `UpdateTradeUseCaseTest`.

**Origem:** revisão de `CreateTradeUseCase` vs `StoreListTradeUseCase` (2026-08-18). Na mesma revisão avaliou-se fundir os dois UseCases num só parametrizado e **decidiu-se não fundir**: eles diferem em gatilho (sessão autenticada × `VerifySecret`), em resolução de supplier (`upsertByUrl` × `resolveBySteamId`), em origem das linhas (linha em branco × pesquisa) e em dependências (`BundleService` só num deles) — um parâmetro de modo cobrindo isso seria flag argument, e a poda acima afasta ainda mais os dois.

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

## Pesquisa de bundle não tem estado — "enfileirada" e "acabou em nada" são indistinguíveis

**Onde:** `app/UseCases/Bundles/ResearchBundleGamesUseCase.php` e o callback `app/UseCases/Trades/StoreListTradeUseCase.php`.

O disparo é fire-and-forget e o contrato do `price_researcher` não tem callback de erro nem de fim: depois do `202`, três desfechos chegam ao operador exatamente iguais — **nada acontece na tela**.

| Desfecho | O que o operador vê | O que realmente houve |
|---|---|---|
| Trade aparece minutos depois | funcionou | jogos qualificaram e viraram linhas |
| Nada aparece, ainda | igual a "acabou em nada" | job na fila (concorrência 1 do lado de lá — pode estar atrás de outro) |
| Nada aparece, nunca | igual a "ainda processando" | nenhum jogo passou pelo piso de popularidade, ou nenhum teve preço encontrado, e o job terminou **sem chamar** o callback |
| Nada aparece, nunca | igual aos dois acima | erro de scraping no `price_researcher`, logado só do lado de lá |

Na prática o operador só descobre disparando de novo, e cada redisparo é minutos de scraping do outro lado.

**Ação:** gravar o estado do disparo por bundle (`requested_at` + desfecho), marcar como "processado sem resultado" por timeout, e mostrar isso no menu do bundle — hoje o item "Pesquisar Preços" fica sempre igual, disparado ou não. Distinguir "acabou sem nada" de "erro" exige mudança no contrato do `price_researcher` (um callback de fim de job, mesmo sem jogos); a parte do timeout dá para fazer só deste lado.

**Origem:** ressalva 1 do contrato de pesquisa de bundle, na implementação da opção "Pesquisar Preços" (2026-09-12).

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

**Ação:** extrair para helpers compartilhados em `tests/Support/` (namespaced, como
`Tests\Support\FinancialMonthFactory` — helper solto no topo de um arquivo é promovido ao
namespace global pelo Pest e colide) — `seedGamivoFees()` e `actingAsAuthorizedUser()`.

**Origem:** code-review da feature `gamivo_id` em trades (2026-07-17).

---

## Regra de validação `games.*.gamivo_id` duplicada em 3 FormRequests

**Onde:**

- `app/Http/Requests/ProspectSupplierRequest.php`
- `app/Http/Requests/StoreListTradeRequest.php`
- `app/Http/Requests/GameRequestArray.php` (pré-existente)

`'games.*.gamivo_id' => ['nullable', 'string']` está copiada identicamente em
três classes. Um endurecimento futuro da regra (ex: exigir apenas dígitos)
precisaria ser aplicado em todas — esquecer uma deixa pontos de entrada com
validação inconsistente.

**Ação:** avaliar um trait/rule-set compartilhado para os campos comuns de
`games.`* entre esses FormRequests, ou um Value Object de validação.

**Origem:** code-review da feature `gamivo_id` em trades (2026-07-17); padrão
de duplicação já existia antes desse trabalho (region, popularity, price_euro
também são copiados entre as mesmas classes).

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