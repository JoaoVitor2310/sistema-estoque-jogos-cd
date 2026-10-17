# Price Researcher — Referência de Integração

Serviço Node.js que pesquisa **popularidade** (SteamCharts) e **preço** (AllKeyShop) de jogos para revendedores. Este documento é destinado ao sistema **Sistema-Estoque** que se comunica com este serviço.

---

## Endereço base

Os dois sistemas rodam em containers Docker na mesma VPS, ligados pela rede `sistema-estoque-cd`. **Dentro de um container, `localhost` é o próprio container**, então o Sistema-Estoque (`app-cd`) chega ao price-researcher pelo nome do container:

| De onde | URL base |
|---|---|
| Sistema-Estoque → Price Researcher (rede `sistema-estoque-cd`) | `http://price-researcher:5555` |
| Host da VPS (testes manuais via SSH) | `http://localhost:5555` |

A porta publicada no host é configurável via `HOST_PORT` no `.env` do price-researcher (default `5555`). A porta interna do container é sempre `5555`, fixa no `docker-compose.yml` — é ela que vale na rede `sistema-estoque-cd`, qualquer que seja o `HOST_PORT`.

No sentido contrário (callbacks), o price-researcher chama o Sistema-Estoque pela URL de `SISTEMA_ESTOQUE_URL` no `.env` dele.

---

## Endpoints

O Sistema-Estoque não usa mais `POST /api/games/search`: as duas pesquisas de bundle passaram para `POST /api/games/research` com **presets** (seção 4).

### 1. `POST /api/games/search-id-steam` — Busca Steam ID por nome

Recebe uma lista de jogos com seus IDs internos e retorna o `id_steam` de cada um, buscando no SteamCharts.

**Request body** (`Content-Type: application/json`):

```json
{
  "games": [
    { "id": 42, "name": "Half-Life 2" },
    { "id": 43, "name": "Portal" }
  ]
}
```

| Campo | Tipo | Descrição |
|---|---|---|
| `games[].id` | `number` | ID interno do jogo no Sistema-Estoque |
| `games[].name` | `string` | Nome do jogo para busca no SteamCharts |

**Response 200:**

```json
{
  "success": true,
  "data": {
    "games": [
      { "id": 42, "name": "Half-Life 2", "id_steam": "220" },
      { "id": 43, "name": "Portal", "id_steam": "400" }
    ]
  }
}
```

> `id_steam` fica **ausente** do JSON se o jogo não for encontrado no SteamCharts.
> O campo `id` é espelhado de volta para correlação no sistema chamador.

---

### 2. `POST /api/lists/run` — Execução assíncrona de listas SteamTrades

Enfileira a busca de listas de trade de um usuário no SteamTrades. Resposta imediata (202); o resultado é enviado para `POST /trades/from-price-researcher` no sistema-estoque quando concluído.

**Request body** (`Content-Type: application/json`):

```json
{
  "steam_id": "76561198012345678",
  "checkGamivoOffer": true
}
```

| Campo | Tipo | Padrão | Descrição |
|---|---|---|---|
| `steam_id` | `string` | — | Steam ID 64-bit do usuário |
| `checkGamivoOffer` | `boolean` | `true` | Só aceita jogos que tenham oferta ativa na Gamivo |

**Response 202** (enfileirado):
```json
{ "success": true, "status": "queued" }
```

**Resultado** enviado para `POST /trades/from-price-researcher` no sistema-estoque quando concluído, com `supplier_steam_id` igual ao `steam_id` enviado (sem `title`).

> Os critérios do fluxo de listas vêm do preset `lists` do price-researcher (não configuráveis via request, exceto `checkGamivoOffer`): popularidade mínima de **30** para jogos até **€5** e de **100** acima de €5; preço precisa ser maior que **€0,50**. Valores vigentes em `GET /api/games/presets`.
> Concorrência controlada por `RUN_LISTS_CONCURRENCY` (default `1`) no `.env`.
> Erro de validação responde `400` com a mensagem em **português** no campo `data`: `{ "success": false, "data": "Erro no corpo da requisição: ..." }`.

---

### 3. `POST /api/suppliers/find-new` — Busca assíncrona de novos fornecedores

Enfileira a busca de novos fornecedores no SteamTrades. Sem corpo. Resposta imediata (202); para cada anúncio processado, o serviço chama `POST /suppliers/prospect` no sistema-estoque e usa a resposta para decidir se comenta.

Os critérios de pesquisa de preço vêm do preset `suppliers`: popularidade mínima de **30** até **€5** e **100** acima, preço maior que **€0,50**, e exige oferta ativa na Gamivo.

**Response 202** (enfileirado):
```json
{ "success": true, "status": "queued" }
```

**Response 500** quando falta configuração no price-researcher — o corpo **não tem** `success`, só a variável que faltou:
```json
{ "error": "STEAMTRADES_SESSION is not defined in .env" }
```

> **A resposta de `POST /suppliers/prospect` pode trazer menos jogos do que foram pesquisados.** Jogo de que já há estoque encalhado é tirado de `profitable` e do `total_tf2_price` — é o que impede o comentário de oferecer o que já está parado. O que ficou de fora **não volta na resposta**: quem decide o que ofertar é o Sistema-Estoque, e o serviço só precisa do que comentar. Lista em que todo jogo lucrativo está encalhado responde `should_comment: false`. Regra em [`docs/PRODUCT.md`](PRODUCT.md#jogo-encalhado-é-sinalizado-na-trade).

> **Nome do preço de mercado.** Nos jogos do pedido e do callback o campo é `market_price_euro`. O nome antigo, `price_euro`, não é mais aceito: um jogo sem `market_price_euro` responde 422 (`games.N.market_price_euro`), e a resposta de `/suppliers/prospect` não o repete em `profitable[]`.
>
> **Moeda de oferta.** `POST /suppliers/prospect` aceita `offer_currency` (`tf2` | `eur` | `usd`; ausente = `tf2`). Em `eur`/`usd` a resposta traz `offer_currency`, `total_offer_price` e `offer_price` em cada item de `profitable`, na moeda pedida — sempre números JSON, arredondados por jogo, com o total somando os já arredondados. `tf2_price`/`total_tf2_price` continuam na resposta. Moeda fora do enum → 422; sem cotação da TF2 na moeda **e** com comentário a postar → 503, sem gravar trade nenhuma. A trade nasce com a moeda pedida. Regra em [`docs/PRODUCT.md`](PRODUCT.md#moeda-da-trade).
>
> O Sistema-Estoque propaga o `202` e a mensagem "Busca de novos fornecedores enfileirada." para o frontend — o botão "Procurar novos" (`Suppliers.vue`) não espera nem recarrega a lista após a chamada, pois o resultado chega de forma assíncrona pelo callback de `/suppliers/prospect`.

---

### 4. `POST /api/games/research` — Pesquisa dos jogos de um bundle, por preset

Pesquisa preço e popularidade de uma lista de jogos. Os critérios não vão no corpo: quem chama manda o nome de um **preset**, e a regra mora no price-researcher — mudar o critério de bundle é mudança de lá, sem deploy do Sistema-Estoque. O corpo sai inteiro de `App\Services\External\BundleResearchRequest`.

#### Presets usados pelo Sistema-Estoque

| Preset | Popularidade mínima | Preço mínimo | Exige oferta na Gamivo | Entrega | Quem usa |
|---|---|---|---|---|---|
| `bundle-research` | 1 (consulta o SteamCharts e descarta o que não existe lá) | 0 | não | assíncrona, pelo callback | `ResearchBundleGamesUseCase` — "Pesquisar Preços" do menu de cada bundle em `Bundles.vue` → `POST /bundles/{bundle}/research` |
| `bundle-sync` | 0 (**não consulta** o SteamCharts) | 0 | não | síncrona (`delivery: "respond"`) | `SyncBundlesFromApiUseCase` — preço de lançamento dos jogos de um bundle, na sync de hora em hora (regra de quando pesquisar em [`GG_DEALS.md`](GG_DEALS.md)) |

A popularidade mínima de um preset tem dois tiers por preço: um mínimo para jogos até `priceThresholdEuro` (inclusive) e outro para os acima. Os dois presets acima usam o mesmo valor nos dois tiers. Os valores vigentes ficam em `GET /api/games/presets` (sem autenticação):

```json
{
  "success": true,
  "presets": {
    "bundle-research": {
      "label": "Bundle Research",
      "popularity": { "upToThreshold": 1, "aboveThreshold": 1, "priceThresholdEuro": 5 },
      "minPrice": 0,
      "checkGamivoOffer": false
    }
  }
}
```

**Request body** (`Content-Type: application/json`):

```json
{
  "preset": "bundle-research",
  "gameNames": ["Taiji", "Viewfinder"],
  "internal_secret": "<INTERNAL_SECRET>",
  "title": "Humble Perplexing Puzzles Bundle"
}
```

A coluna "Obrigatório" é a regra **do Sistema-Estoque**. Para o price-researcher só `gameNames` é obrigatório; os demais têm o comportamento descrito em "Sem o campo".

| Campo | Tipo | Obrigatório | Sem o campo | Descrição |
|---|---|---|---|---|
| `preset` | `string` | sim | aplica o preset `default` (popularidade 30 até €5 / 100 acima, preço mínimo €0,50) — **não** é o critério de bundle | Nome do preset |
| `gameNames` | `string[]` | sim | `400` | Nomes dos jogos do bundle (mín. 1 item) |
| `internal_secret` | `string` | sim | modo demo (ver ressalva abaixo) | Autentica o disparo |
| `delivery` | `"trade"` \| `"respond"` | não | `trade` | `trade`: assíncrono, resultado pelo callback. `respond`: síncrono, os jogos voltam na resposta e **nenhuma trade é criada**. Ignorado sem token válido |
| `title` | `string` | sim, sem `delivery: "respond"` | a trade nasce sem título | Nome do bundle. Volta **idêntico** no callback — é por ele que a trade nasce com o nome do bundle. A sync, com `respond`, não o envia |
| `steam_id` | `string` | não | — | Volta como `supplier_steam_id` no callback. Ignorado com `respond` |
| `list_code` | `string` | não | — | Volta como `list_code` no callback. Ignorado com `respond` |

> **Não mande critério avulso.** `popularity`, `minPrice`, `checkGamivoOffer` e `minPopularity` são aceitos e **sobrescrevem** o preset campo a campo — o que esconderia qualquer mudança futura dele. O Sistema-Estoque manda só o preset. `minPopularity`, em particular, é um atalho temporário da migração e **vai ser removido** do price-researcher; depois disso, mandá-lo responde `400`.
>
> **Schema estrito:** campo desconhecido no corpo derruba a request com `400`. Não acrescente chaves sem combinar com o price-researcher antes.

**Response 202** (`trade`, enfileirado):
```json
{ "success": true, "status": "queued" }
```

**Response 200** (`delivery: "respond"`):
```json
{
  "success": true,
  "games": [
    { "name": "Hades", "market_price_euro": 12.5, "popularity": 0, "region": "", "id_steam": null, "gamivo_id": null }
  ]
}
```

`market_price_euro` é número. `region` vazio (`""`) é key global; os outros valores vêm em maiúsculas (`EU`, `ROW`). Jogo que o AllKeyShop não encontrou **não aparece** na lista. A sync grava `market_price_euro` em `bundle_games.bundle_launch_price`, casando pelo `name` (idêntico ou normalizado).

**Response 400** — a mensagem vem em `data`, não em `details`:
```json
{ "success": false, "data": "Invalid file content: preset must be one of: default, bundle-research, bundle-sync, lists, suppliers" }
```

> **Requisição longa.** Com `respond` o bundle inteiro é pesquisado dentro da requisição, um jogo por vez, na mesma fila do navegador das pesquisas em andamento — pode levar vários minutos. O cliente da sync espera até `SyncBundlesFromApiUseCase::LAUNCH_PRICES_TIMEOUT_SECONDS`; o limite que vale na prática é o menor entre ele e o `SERVER_TIMEOUT_MS` do price-researcher.

> ⚠️ **Modo demo é falso sucesso.** Sem `internal_secret` (ou com ele errado) o serviço **não recusa**: responde `200` com `{ "success": true, "demo": true, "games": [...] }`, processa só os 10 primeiros jogos e **nunca chama o callback**. Os dois chamadores tratam `demo: true` como erro de configuração: `ResearchBundleGamesUseCase` responde 500, e a sync loga e não grava nada — senão gravaria o preço de um bundle cortado pela metade como se fosse o resultado completo. Sem segredo configurado, nenhum dos dois chega a chamar o serviço.

**Preço:** o `market_price_euro` é o melhor preço do AllKeyShop entre os marketplaces — nunca foi o preço da Gamivo. "Exigir oferta na Gamivo" só descarta o jogo que não tem oferta lá; o preço continua sendo o melhor de todos.

---

## Callback — `POST /trades/from-price-researcher`

Endpoint **do sistema-estoque**, destino do resultado dos fluxos assíncronos (endpoints 2 e 4). Autenticado por `Authorization: Bearer <EXTERNAL_SECRET>` (middleware `VerifySecret`; sem o header, `401`). Path fixo, definido do lado do price-researcher.

```json
{
  "title": "Humble Perplexing Puzzles Bundle",
  "games": [
    { "name": "Taiji", "market_price_euro": 1.23, "popularity": 542, "region": "", "id_steam": "70", "gamivo_id": "12345" }
  ]
}
```

Campos do topo, cada um **só aparece se tiver valor**: `title` (pesquisa de bundle), `supplier_steam_id` (fluxo de listas, ou `steam_id` enviado na pesquisa) e `list_code`. Em cada jogo, `region` segue a mesma regra da resposta do `respond`: `""` é key global, os outros valores em maiúsculas (`EU`, `ROW`); `id_steam` e `gamivo_id` podem ser `null`.

`StoreListTradeUseCase` cria a trade com `title` e uma linha por jogo (`TradeLineBuilder::fromResearch`). Qualquer `2xx` é sucesso; um `4xx`/`5xx`, ou nenhuma resposta em **15 segundos**, é apenas logado do lado do price-researcher — **não há retry**.

O `title` não é só o nome da trade: quando a trade **não tem supplier** e o título nomeia um bundle existente, toda linha é atribuída a ele (`BundleService::bundleByTitle()`) — é o que permite reconstruir "estes jogos são deste bundle" mesmo quando o AllKeyShop renomeia o jogo ou o bundle já saiu da janela recente. Nos demais casos vale o palpite por nome + recência. Tabela completa em [`agents/domain-map.md`](agents/domain-map.md#6-suppliers-e-trades-suppliertradetradeline--tabelas-supplierstradestrade_lines).

> **Lista ou bundle sem jogo qualificado não gera callback nenhum.** Se todos caírem pelo piso de popularidade, pelo piso de preço ou ficarem sem preço encontrado, o job termina sem chamar o endpoint, e o sistema-estoque não tem como distinguir "ainda processando" de "acabou sem resultado". Pendência registrada em [`IMPROVEMENTS.md`](IMPROVEMENTS.md). Com os critérios frouxos do preset `bundle-research` (popularidade 1, preço mínimo 0, sem filtro de Gamivo) isso ficou raro, mas não impossível — bundle inteiro de jogo que o AllKeyShop não conhece volta vazio.

---

## Comportamento geral de preços

- **Fonte de popularidade:** SteamCharts (pico de jogadores nas últimas 24h)
- **Fonte de preço:** AllKeyShop — o melhor preço entre os marketplaces. A Gamivo entra de dois jeitos: o critério "exigir oferta na Gamivo" descarta jogos sem oferta lá, e o `gamivo_id` é buscado na API da Gamivo quando há oferta
- A popularidade mínima depende do preço: um mínimo até o limite de preço (inclusive) e outro acima. Jogos que não alcançam nem o **menor** dos dois são descartados antes da busca de preço; os demais são checados contra o mínimo da faixa do seu preço depois dela
- Popularidade mínima **0 nos dois tiers** desliga o filtro e nem consulta o SteamCharts. Com só um dos dois em 0, a consulta acontece, e jogo que o SteamCharts não conhece é descartado
- Jogo sem preço encontrado não entra no resultado
- Jogo com preço **≤ preço mínimo** do preset é descartado
- Nomes são normalizados internamente (algarismos romanos → arábicos, sufixos de edição removidos, etc.) — não é necessário tratar o nome antes de enviar

---

## Erros comuns

O formato do erro **varia por rota** — cada uma devolve a mensagem num campo diferente:

| Rota | `400` (validação) | `500` (erro interno) |
|---|---|---|
| `/api/games/search-id-steam` | `{ "success": false, "error": "Validation failed", "details": "..." }` | `{ "success": false, "error": "Internal server error", "message": "Failed to analyze games" }` |
| `/api/games/research` | `{ "success": false, "data": "Invalid file content: ..." }` | `{ "success": false, "error": "Internal server error." }` (com ponto final) |
| `/api/lists/run` | `{ "success": false, "data": "Erro no corpo da requisição: ..." }` (em português) | — |
| `/api/suppliers/find-new` | — (sem corpo) | `{ "error": "<VARIÁVEL> is not defined in .env" }` (sem `success`) |

| HTTP | Causa provável |
|---|---|
| `400` | Body com campos ausentes, desconhecidos ou de tipo inválido |
| `404` | Path errado — confirmar que usa `/api/games/...`, `/api/lists/...` e `/api/suppliers/...` |
| `500` com `"connect ECONNREFUSED 127.0.0.1:<porta>"` | O Chromium não conseguiu iniciar — em produção foi falta de processos no container (`pids_limit`), por navegadores antigos que não foram fechados. Reiniciar o container libera |
| `500` genérico | Erro de scraping; pode ser temporário (AllKeyShop / SteamCharts fora do ar) |

---

## Variáveis de ambiente relevantes (`.env` do price-researcher)

| Variável | Padrão | Descrição |
|---|---|---|
| `HOST_PORT` | `5555` | Porta publicada no host pelo Docker |
| `PORT` | `5555` | Porta interna do app (Nest). **Fixa em `5555` no `docker-compose.yml`** — o valor do `.env` não vale no container |
| `INTERNAL_SECRET` | — | Segredo que o Sistema-Estoque manda em `internal_secret`. Sem ele configurado, `/api/games/research` sempre responde em modo demo |
| `EXTERNAL_SECRET` | — | Segredo que o price-researcher manda no `Authorization: Bearer` dos callbacks. Precisa bater com o `VerifySecret` do Sistema-Estoque |
| `SISTEMA_ESTOQUE_URL` | — | URL base dos callbacks no Sistema-Estoque |
| `SERVER_TIMEOUT_MS` | `600000` (10 min) | Timeout do servidor HTTP |
| `RUN_LISTS_CONCURRENCY` | `1` | Máx. de execuções paralelas do fluxo de listas |
| `MAX_ACTIVE_LISTS` | `3` | Máx. de listas ativas por usuário processadas |
| `STEAMTRADES_PAGE_DELAY_MS` | — | Delay entre page loads do SteamTrades (throttling) |
