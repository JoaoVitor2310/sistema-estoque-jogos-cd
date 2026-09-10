# Segurança e guardrails

Padrões defensivos já quebrados uma vez neste repo — cada regra abaixo existe por causa de um incidente real, citado entre parênteses.

## API Gamivo é produção real — nunca chamar sem autorização explícita

`API_KEY_GAMIVO` e `API_GAMIVO_URL` apontam para o ambiente de produção. Qualquer chamada real à API Gamivo (criar oferta, atualizar preço, fazer upload de chave, etc.) pode ter efeito imediato no estoque e nas vendas. Regras:

1. **Nunca executar um endpoint Gamivo sem o usuário autorizar explicitamente** naquela sessão.
2. **Sempre que precisar de um produto/oferta para testar**, perguntar ao usuário qual pode ser usado — nunca assumir ou inventar.
3. Em testes automatizados, usar sempre `Http::fake()` — jamais permitir que um teste chegue à API real.
4. Em desenvolvimento local, preferir o endpoint `calculate-customer-price` / `calculate-seller-price` (somente leitura) para validar cálculos antes de qualquer PUT/POST.

## Permissões são obrigatórias

Toda rota nova deve declarar explicitamente quem pode acessá-la. Perguntas a responder antes de registrar qualquer rota:

1. Guest pode acessar?
2. É página da equipe (`RequireTeam`)?
3. É API/mutação da equipe (`CheckPermission`)?
4. Requer admin (`CheckAdmin`)?

Os três middlewares aplicam a **mesma** régua — o gate `can-edit` — e mudam só o formato da recusa: `RequireTeam` manda o visitante sem sessão para `/login` e responde 403 a quem tem sessão e não é da equipe; `CheckPermission` responde 403 JSON; `CheckAdmin` idem, com o gate `is-admin`. Nunca deixar rota sem middleware assumindo que "ninguém vai acessar". Após adicionar rotas, adicionar testes de acesso em `tests/Feature/Security/GuestAccessTest.php` (visitante) e `tests/Feature/Security/RegisteredUserAccessTest.php` (conta de fora).

**"Estar logado" não é permissão.** `RequireTeam` nasceu de `RequireAuth`, que exigia só sessão — e sessão é o que qualquer visitante cria em `/register`, que é aberto e não pede verificação de e-mail. Enquanto a diferença não importava, `/assets`, `/sales`, `/financial-months`, `/games`, `/fees` e `/acesso` ficaram abertas a qualquer conta: nenhuma mostra `key_code`, mas mostram o caixa da operação e a lista de quem tem acesso. A entrega (`docs/adr/0008`) levou terceiros ao domínio e transformou isso de teórico em provável. Corrigido em 2026-08-19.

## Decisões de segurança já tomadas — não reabrir sem motivo novo

Três coisas que uma revisão encontra e classifica como problema, e que **são deliberadas**. Quem
for propor mudança precisa de argumento novo, não da observação de sempre:

- **Não existe mais página pública, e a que voltar não será a aba interna.** `/keys` e `/bundles`
  respondiam a visitante — a leitura de keys já saía filtrada por `GuestKeyVisibility`, mas custo,
  margem e catálogo ficavam à vista, e desde a entrega os suppliers conhecem o domínio. Fechadas em
  2026-08-19. A vitrine volta um dia como **página própria de portfólio**, escrita para ser vista;
  abrir a aba de novo, não. `GuestKeyVisibility` e a whitelist do `IndexKeysRequest` continuam no
  código de propósito: viraram a segunda barreira, e são o ponto de partida daquela página.
- **`AutoSellUseCase` grava `key_code` no log do scheduler.** Serve para reconstruir o que foi
  listado quando a Gamivo diverge do nosso estado, e a retenção é de 30 dias. Aceito: o arquivo
  vive na VPS, com o mesmo acesso que já alcança o banco.
- **A porta 5433 do Postgres é loopback.** Esteve publicada em `0.0.0.0` e foi encontrada aberta à
  internet em 2026-08-19. Ela não existe para a aplicação, que fala com o banco pela rede interna
  do compose — existe para client de fora, e o caminho para isso é túnel SSH. Republicar em
  `0.0.0.0` expõe todas as `key_code` em claro atrás de uma senha só.

## Em rota pública, esconder o campo não basta — o filtro também é superfície

Regra permanente, ainda que hoje nenhuma rota de leitura seja pública — ela vale para a próxima que for. Mascarar a saída (`only(GuestKeyVisibility::FIELDS)`) enquanto o filtro aceita qualquer coluna deixa um **oráculo cego**: a linha some, mas o total de resultados ainda responde "existe registro com esse valor?", e repetir a pergunta com prefixos crescentes reconstrói o dado escondido. Todo endpoint de busca declara a whitelist de filtros num FormRequest, e a whitelist é **escopada pela mesma permissão que escopa a resposta** — se o visitante não recebe a coluna, ele não pode filtrar por ela. Filtro proibido devolve 403; ignorar em silêncio mentiria sobre o resultado. Nunca monte query a partir de `$request->all()`/`except()`: além do vazamento, nome de coluna vindo do cliente vira 500 assim que uma coluna é renomeada. *(Aconteceu: `POST /keys/search` permitia enumerar `key_code`, `supplier_url` e `notes` — ver `IndexKeysRequest`.)*

## A entrega de trade é pública — e é a única rota do sistema que é

`/deliveries/{uuid}` vive fora de `RequireTeam` e de `CheckPermission` **de propósito**: o usuário é o supplier, não a equipe. Quem autoriza é o token da entrega, conferido em `POST .../token` e exigido pelo `EnsureDeliverySession` em toda escrita. Três coisas não podem ser afrouxadas ali:

- **A leitura é por lista explícita de colunas** (`DeliveryReadModel::LINE_COLUMNS`), nunca `all()` nem `toArray()` do model. `market_price`, `popularity` e `gamivo_id` são a saída do `price_researcher` — quanto o jogo do supplier vale para nós. Vazar isso não é incidente pontual: é entregar a margem para **todo** supplier, para sempre. Pelo mesmo motivo nenhuma resposta de escrita devolve o registro salvo. **`bundle` é exceção deliberada** (2026-08-19): sai na leitura e **entra na escrita** — é o único campo pesquisado que o supplier grava. O medo original — "ele descobre que o jogo é barato e renegocia" — não se aplica a este campo: quem entregou a key sabe de onde ela veio, e a origem em bundle puxa o preço para **baixo**, a favor de quem compra. O que ele sobrescreve é o palpite do lookup, não um número de precificação; o preço pesquisado continua fora dos dois lados. Ampliar qualquer uma das duas listas é decisão de segurança — o próximo campo não entra "porque ajudaria a preencher".
- **O escopo de escrita é `TradeLineAuthority`, no UseCase** — não as regras do Form Request. Se a barreira fosse validação, ampliar o alcance do supplier seria acrescentar uma regra, e ninguém lê isso como decisão de segurança.
- **CSRF é reativado nessas rotas** por `ValidateDeliveryCsrfToken`, porque o projeto o desliga globalmente (`bootstrap/app.php`) — decisão que se sustentava enquanto toda escrita era autenticada. Uma superfície pública com sessão de 12h muda essa premissa.

Rate limit no token nos **dois** eixos (por entrega e por IP): só por IP um atacante distribui; só por entrega dá para travar de propósito a entrega de um supplier legítimo. Números em `App\Domain\Trades\DeliveryCredential`. O 429 devolve o tempo restante (`Retry-After` e mensagem): a janela é de uma hora, e sem o número o supplier volta cedo demais e conclui que o link quebrou.

**Url malformada é 404, nunca 500 nem redirect.** O grupo tem `whereUuid('trade')` — sem ele a string chega ao Postgres como `where delivery_uuid = '...'` e estoura `QueryException` com stack trace numa rota pública *(já aconteceu)*. E um `Route::any('deliveries/{path}')` logo depois do grupo devolve 404 no que sobra, porque o `Route::fallback` global redireciona para `/keys` — despejar quem errou o link na aba interna revela que ela existe. O caso não é reproduzível pelo banco na suíte (o SQLite aceita qualquer texto na coluna): o teste é do roteador.

**Entregue é fechado.** `EnsureDeliverySession` recusa com 409 toda gravação do supplier depois do clique em entregar — inclusive um segundo `deliver` —, e a regra em si é de domínio (`TradeDeliveryState::acceptsSupplierWrites()`), não um `if` no middleware. Não é sobre autenticação: a sessão dele continua válida, o que fechou foi a janela. O que isso protege é a única cópia que existe das keys entre a entrega e o import — `trade_lines` guarda só o valor atual, e um `PATCH key_code=''` apaga sem deixar rastro. Se algum dia aparecer um "reabrir entrega", ele precisa ser ação da equipe, autenticada, e não um caminho que o próprio supplier alcance.

O token fica **encriptado** (cast `encrypted` em `Trade`), não em hash, porque a aba de Trades o exibe para a equipe copiar. Duas consequências que não podem ser afrouxadas: ele sai **só** pela projeção do `TradeService` — a projeção da entrega (`DeliveryTradeResource`) não devolve token nenhum —, e `delivery_token` está no `$hidden` do model, para não escapar numa serialização automática. Um `toArray()` de `Trade` numa rota nova não pode virar o caminho por onde o token de toda trade vaza.

**Ler o token nunca pode derrubar a página.** Toda leitura passa por `Trade::readableDeliveryToken()`, que devolve `null` quando o valor guardado não abre com a `APP_KEY` corrente — chave rotacionada, ou um dump de produção aberto em outro ambiente. Sem isso, o cast lança `DecryptException` e, como a projeção lê o token de toda trade da página, **uma** linha ilegível derruba a aba inteira com 500 *(já aconteceu)*. Degradar assim não afrouxa nada: um token que não abre não confere com nada, e a entrega correspondente fica fechada.

## Lote é `whereIn`, não loop

Exclusão/atualização em massa não itera chamando `find()` + `delete()` por item: se um id falha no meio, os anteriores já foram gravados e a resposta de erro descreve um estado que mudou pela metade. Valide a existência **na fronteira** (`exists:tabela,id` no FormRequest, ver `DeleteManyRequest`) e execute num statement só — assim o lote é atômico por construção, sem precisar de transação, e ainda deixa de ser N+1. *(Já aconteceu: 4 dos 5 `destroyArray` apagavam parcialmente e respondiam erro.)*

## Seleção de DataTable vai no corpo do DELETE, nunca como query params

Toda tela com exclusão em lote (`DeleteManyRequest`) manda a seleção do PrimeVue DataTable via `axiosInstance.delete(url, { data: { <itemsKey>: [...] } })`, reduzida a `{id}` por linha antes do envio — nunca a linha inteira, e nunca em `params` (axios sempre serializa `params` na query string, mesmo em `DELETE`). *(Já aconteceu: `Keys.vue` mandava a linha completa do DataTable — incluindo o `supplier` aninhado — como `params`; 5 keys selecionadas já bastavam para estourar o limite de URL do nginx com 414 Request-URI Too Large. O mesmo padrão existia copiado em mais 6 telas.)*

## `detach()` sem argumento apaga tudo

No Eloquent, `$model->relation()->detach(null)` desvincula **todos** os registros, não nenhum — então rota de remoção sem FormRequest transforma payload vazio em "esvazie a relação inteira", respondendo 200. Toda rota que remove vínculo declara `required|array|min:1`. *(Já aconteceu: `DELETE /bundles/{bundle}/games` sem `games` limpava o bundle inteiro.)*

## `delete()` é soft nas 7 tabelas curadas

`keys`, `trades`, `suppliers`, `games`, `bundles`, `financial_months` e `financial_movements` usam `SoftDeletes`: a linha ganha `deleted_at` e some das queries Eloquent, mas continua no banco. Três consequências que não se percebem lendo o código de chamada:

| Armadilha | O que fazer |
|---|---|
| Query builder cru (`DB::table(...)`) **não** aplica o global scope | filtrar `->whereNull('deleted_at')` à mão |
| Os `ON DELETE` do banco (`nullOnDelete`/`cascadeOnDelete`) **não disparam** em soft-delete | cascata que importa vive no app (ex: `ReopenFinancialMonthUseCase` apaga os movimentos do draft descartado explicitamente) |
| `unique` bloquearia recriar um valor de linha apagada | virou índice parcial `WHERE deleted_at IS NULL` em `suppliers.steam_id` e `financial_months (year, month)` |

Somar dinheiro é o caso mais sensível: `FinancialMonthService::accountBalances` deriva o saldo de `$month->movements` (Eloquent, respeita o scope). Trocar por `DB::table('financial_movements')->sum()` voltaria a contar linha apagada. Ver [`docs/adr/0011`](../adr/0011-soft-delete-on-curated-tables.md).

## Validação com enums usa `Rule::enum()`

Nunca use `'in:valor1,valor2'` para validar um campo que tem enum correspondente. Use `Rule::enum(MinhaEnum::class)` no FormRequest. Assim a validação se mantém sincronizada automaticamente quando o enum crescer.

## Alerta por e-mail é um Mailable, e o destinatário vem da config

Nada de `Mail::send`/`Mail::raw` com closure e endereço no meio do código: cada alerta é uma classe em `app/Mail/` (padrão de `GamivoTokenExpiredMail`) enviada para `config('app.admin_email')`, declarado em `config/app.php` **sem fallback** — `ADMIN_EMAIL` é garantido em todos os ambientes, e endereço embutido no código só esconderia um ambiente mal configurado. Envio de alerta vai **sempre** em `try/catch` com log, sem exceção: além de o SMTP poder cair, `Mail::to(null)` lança `An email must have a "To" header`, e uma config faltando não pode derrubar a tarefa que detectou o problema.

## Fallback de config depende do que a ausência causa

Antes de escrever um default, pergunte de que lado erra melhor: para *entrega*, mandar ao endereço padrão pode ser melhor que não mandar; para *autorização*, conceder acesso por omissão de config é o pior desfecho possível. Por isso `admin_email` (entrega) e `admin_gate_email` (Gate `is-admin`, definido em `AppServiceProvider`) são chaves separadas mesmo lendo hoje o mesmo `ADMIN_EMAIL` sem fallback — a separação existe para que um default reintroduzido de um lado nunca vaze para o outro.

## `.env.example` é ambiente de verdade

O CI faz `cp .env.example .env` e todo clone novo nasce dele: variável obrigatória deixada em branco ali significa suíte rodando com config vazia e sistema novo nascendo quebrado. Ao remover um fallback, preencha o `.env.example` no mesmo passo.

## Serviço fora do ar não é status HTTP

`Http::get`/`post` lança `ConnectionException` quando não conecta — host que não resolve, porta fechada, timeout — e nesse caso **não existe `$response` para inspecionar**: checar `$response->failed()` nunca roda. Onde houver caminho de alerta para falha HTTP, a falha de conexão tem que chegar **no mesmo caminho** — o `try/catch` vai em volta da própria chamada, não só do envio de e-mail. É o modo de falha mais provável dos dois. *(Já aconteceu: `price-researcher-dev` parado derrubou o `ResolveSteamIdsUseCase` com stack trace e nenhum e-mail.)*

Não é regra de capturar sempre: em `GamivoApiService` a `ConnectionException` **deve** escapar. Ali `handleResponse` traduz erro HTTP em `RuntimeException` e `getMyOfferForProduct` traduz isso em `null` = "não há oferta" — capturar a falha de conexão no mesmo lugar faria a Gamivo inacessível parecer "produto sem oferta" e o sistema criaria oferta duplicada. Deixar estourar faz o scheduler pular o ciclo e tentar de novo no minuto seguinte, que é o desfecho certo. Antes de capturar, pergunte em que a exceção vai virar.

## Serviço externo que falha não pode devolver número plausível

`CurrencyConversionService::convertCurrency` responde com o valor de *entrada* quando a API cai. Quem agrega esse retorno tem que **omitir** o que não converteu (ver `convertAll`), nunca repassar: um `price_dollar` que na verdade é o montante em real passa por cotação real e vira alerta falso ou preço gravado errado. Regra geral: falha de integração vira ausência explícita, não valor default.
