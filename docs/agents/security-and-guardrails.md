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
2. Requer autenticação (`RequireAuth`)?
3. Requer `can-edit` (`CheckPermission`)?
4. Requer admin (`CheckAdmin`)?

Rotas de página usam `RequireAuth` (redirect para `/login`); rotas de API/mutação usam `CheckPermission` (retorna 403 JSON). Nunca deixar rota sem middleware assumindo que "ninguém vai acessar". Após adicionar rotas, adicionar testes de acesso em `tests/Feature/Security/GuestAccessTest.php` cobrindo: guest bloqueado, usuário autorizado liberado.

## Em rota pública, esconder o campo não basta — o filtro também é superfície

Mascarar a saída (`only(GUEST_VISIBLE_FIELDS)`) enquanto o filtro aceita qualquer coluna deixa um **oráculo cego**: a linha some, mas o total de resultados ainda responde "existe registro com esse valor?", e repetir a pergunta com prefixos crescentes reconstrói o dado escondido. Todo endpoint de busca declara a whitelist de filtros num FormRequest, e a whitelist é **escopada pela mesma permissão que escopa a resposta** — se o visitante não recebe a coluna, ele não pode filtrar por ela. Filtro proibido devolve 403; ignorar em silêncio mentiria sobre o resultado. Nunca monte query a partir de `$request->all()`/`except()`: além do vazamento, nome de coluna vindo do cliente vira 500 assim que uma coluna é renomeada. *(Aconteceu: `POST /keys/search` permitia enumerar `key_code`, `supplier_url` e `notes` — ver `IndexKeysRequest`.)*

## Lote é `whereIn`, não loop

Exclusão/atualização em massa não itera chamando `find()` + `delete()` por item: se um id falha no meio, os anteriores já foram gravados e a resposta de erro descreve um estado que mudou pela metade. Valide a existência **na fronteira** (`exists:tabela,id` no FormRequest, ver `DeleteManyRequest`) e execute num statement só — assim o lote é atômico por construção, sem precisar de transação, e ainda deixa de ser N+1. *(Já aconteceu: 4 dos 5 `destroyArray` apagavam parcialmente e respondiam erro.)*

## `detach()` sem argumento apaga tudo

No Eloquent, `$model->relation()->detach(null)` desvincula **todos** os registros, não nenhum — então rota de remoção sem FormRequest transforma payload vazio em "esvazie a relação inteira", respondendo 200. Toda rota que remove vínculo declara `required|array|min:1`. *(Já aconteceu: `DELETE /bundles/{bundle}/games` sem `games` limpava o bundle inteiro.)*

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
