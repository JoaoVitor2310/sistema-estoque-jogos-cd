# Price Researcher — Referência de Integração

Serviço Node.js que pesquisa **popularidade** (SteamCharts) e **preço** (AllKeyShop/Gamivo) de jogos para revendedores. Este documento é destinado ao sistema **Sistema-Estoque** que se comunica com este serviço.

---

## Endereço base

| Ambiente | URL base |
|---|---|
| Produção (servidor compartilhado) | `http://localhost:5555` |
| Sistema-Estoque → Price Researcher (mesmo servidor) | `http://localhost:5555` |

Como o Sistema-Estoque e o Price Researcher rodam no **mesmo servidor**, use `localhost:5555` nas chamadas internas — evita tráfego de rede desnecessário. Use o IP público apenas para acesso externo ou testes manuais.

A porta é configurável via variável `HOST_PORT` no `.env` do price-researcher (default `5555`).

---

## Endpoints

### 1. `POST /api/games/search` — Busca por JSON

Busca popularidade e preço de uma lista de jogos. Resposta síncrona (aguarda todo o scraping).

**Request body** (`Content-Type: application/json`):

```json
{
  "gameNames": ["Half-Life 2", "Portal", "Hades"],
  "minPopularity": 50,
  "checkGamivoOffer": true
}
```

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `gameNames` | `string[]` | sim | Nomes dos jogos (mín. 1 item) |
| `minPopularity` | `number >= 0` | sim | Pico de jogadores mínimo em 24h no SteamCharts. `0` = ignora filtro de popularidade |
| `checkGamivoOffer` | `boolean` | sim | `true` = retorna apenas jogos com oferta ativa na Gamivo |

**Response 200:**

```json
{
  "success": true,
  "data": {
    "games": [
      {
        "id": 0,
        "name": "Half-Life 2",
        "foundName": "Half-Life 2",
        "id_steam": "220",
        "popularity": 1234,
        "region": "GLOBAL",
        "GamivoPrice": "1.99",
        "G2APrice": null,
        "KinguinPrice": null
      }
    ],
    "summary": {
      "totalRequested": 3,
      "foundGames": 2,
      "worthyByPopularity": 1,
      "foundPrices": 1,
      "processingTimeSeconds": 12.4
    }
  }
}
```

> **Atenção:** só retorna jogos que passaram no filtro de popularidade **e** tiveram preço encontrado.
> O descarte é silencioso — jogo que não qualificou simplesmente não aparece no resultado.

**Response 400** (validação):
```json
{ "success": false, "error": "Validation failed", "details": "gameNames: Pelo menos um nome de jogo é necessário" }
```

**Response 500:**
```json
{ "success": false, "error": "Internal server error", "message": "Failed to analyze games" }
```

---

### 2. `POST /api/games/upload` — Busca por arquivo `.txt`

Mesmo fluxo do endpoint acima, mas recebe um arquivo `.txt` via `multipart/form-data`. Resposta é o download de um `.txt` formatado para colar em planilha.

**Request** (`Content-Type: multipart/form-data`):

| Campo | Tipo | Descrição |
|---|---|---|
| `fileToUpload` | `file` (text/plain, máx 1 MB) | Arquivo `.txt`: **linha 1** = popularidade mínima (número), **linhas seguintes** = nomes dos jogos |
| `checkGamivoOffer` | `string` `"true"` / `"false"` | Se deve filtrar somente jogos com oferta na Gamivo |

Formato do arquivo:
```
50
Half-Life 2
Portal
Hades
```

**Response 200:** arquivo `.txt` como download (`Content-Disposition: attachment`).

> Este endpoint é usado pela UI web (`public/index.html`). O Sistema-Estoque deve preferir `POST /api/games/search` para integração programática.

---

### 3. `POST /api/games/search-id-steam` — Busca Steam ID por nome

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

> `id_steam` é `undefined` / ausente se o jogo não for encontrado no SteamCharts.
> O campo `id` é espelhado de volta para correlação no sistema chamador.

---

### 4. `POST /api/lists/run` — Execução assíncrona de listas SteamTrades

Enfileira a busca de listas de trade de um usuário no SteamTrades. Resposta imediata (202); o resultado é enviado diretamente para `POST /trades/from-price-researcher` no sistema-estoque quando concluído.

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
| `checkGamivoOffer` | `boolean` | `true` | Filtrar somente ofertas ativas na Gamivo |

**Response 202** (enfileirado):
```json
{ "success": true, "status": "queued" }
```

**Resultado** enviado para `POST /trades/from-price-researcher` no sistema-estoque quando concluído.

> Popularidade mínima é fixa em **30** para o fluxo de listas (não configurável via request).
> Concorrência controlada por `RUN_LISTS_CONCURRENCY` (default `1`) no `.env`.

---

### 5. `POST /api/suppliers/find-new` — Busca assíncrona de novos fornecedores

Enfileira a busca de novos fornecedores no SteamTrades. Resposta imediata (202); cada fornecedor encontrado é enviado individualmente para `POST /suppliers/prospect` no sistema-estoque conforme processado.

**Response 202** (enfileirado):
```json
{ "success": true, "status": "queued" }
```

> O Sistema-Estoque propaga o `202` e a mensagem "Busca de novos fornecedores enfileirada." para o frontend — o botão "Procurar novos" (`Suppliers.vue`) não espera nem recarrega a lista após a chamada, pois o resultado chega de forma assíncrona pelo callback de `/suppliers/prospect`.

---

### 6. `POST /api/games/research` — Pesquisa assíncrona dos jogos de um bundle

Enfileira a pesquisa de preço e popularidade de uma lista de jogos. Resposta imediata (202); o resultado é enviado para `POST /trades/from-price-researcher` no sistema-estoque quando concluído, e vira uma trade.

Disparado pela opção **"Pesquisar Preços"** do menu de cada bundle em `Bundles.vue` → `POST /bundles/{bundle}/research` → `ResearchBundleGamesUseCase`.

**Request body** (`Content-Type: application/json`):

```json
{
  "minPopularity": 1,
  "gameNames": ["Taiji", "Viewfinder"],
  "checkGamivoOffer": false,
  "minPrice": 0,
  "internal_secret": "<INTERNAL_SECRET>",
  "title": "Humble Perplexing Puzzles Bundle"
}
```

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `minPopularity` | `number` | sim | Pico mínimo de jogadores em 24h. Bundles usam `1` (`BundleResearchRequest::MIN_POPULARITY`) — não `0`, que desligaria o filtro: o piso só corta o que o SteamCharts não conhece |
| `gameNames` | `string[]` | sim | Nomes dos jogos do bundle (mín. 1 item) |
| `checkGamivoOffer` | `boolean` | sim | `false` para bundles (`BundleResearchRequest::CHECK_GAMIVO_OFFER`) — jogo sem oferta ativa na Gamivo **não** é descartado; volta com `gamivo_id` nulo |
| `minPrice` | `number >= 0` | não | Piso de preço em euros: jogo com preço **menor ou igual** a ele é descartado. **Omitido, vale o default do serviço, €0,50.** Bundles mandam `0` (`BundleResearchRequest::MIN_PRICE`) — o pacote é precificado completo, e o default cortaria os jogos mais baratos. Como o corte inclui o piso, jogo a exatamente €0,00 ainda é descartado |
| `internal_secret` | `string` | sim | Autentica o disparo. Ver a ressalva do modo demo abaixo |
| `title` | `string` | sim | Nome do bundle. Volta **idêntico** no callback — é por ele que a trade nasce com o nome do bundle |
| `steam_id` | `string` | não | Volta como `supplier_steam_id` no callback |
| `list_code` | `string` | não | Volta como `list_code` no callback |

> **Schema estrito:** campo desconhecido no corpo derruba a request com `400`. O payload sai inteiro de `App\Domain\Bundles\BundleResearchRequest::payload()` — não acrescente chaves sem combinar com o price-researcher antes.

**Response 202** (enfileirado):
```json
{ "success": true, "status": "queued" }
```

> ⚠️ **Modo demo é falso sucesso.** Sem `internal_secret` (ou com ele errado) o serviço **não recusa**: responde `200` com `{ "success": true, "demo": true, "games": [...] }`, processa só 10 jogos e **nunca chama o callback**. `ResearchBundleGamesUseCase` trata esse `200` como erro de configuração (500 + log) justamente porque o disparo pareceria ter dado certo e nunca viraria trade.

**Preço:** o `price_euro` que volta no callback é o melhor preço do AllKeyShop entre os marketplaces — nunca foi o preço da Gamivo, e com `checkGamivoOffer: false` o jogo pode nem ter oferta lá.

---

## Callback — `POST /trades/from-price-researcher`

Endpoint **do sistema-estoque**, destino do resultado dos fluxos assíncronos (endpoints 4 e 6). Autenticado por `Authorization: Bearer <EXTERNAL_SECRET>` (middleware `VerifySecret`; sem o header, `401`). Path fixo, definido do lado do price-researcher.

```json
{
  "title": "Humble Perplexing Puzzles Bundle",
  "games": [
    { "name": "Taiji", "price_euro": 1.23, "popularity": 542, "region": "global", "id_steam": "70", "gamivo_id": "12345" }
  ]
}
```

`StoreListTradeUseCase` cria a trade com `title` e uma linha por jogo (`TradeLineBuilder::fromResearch`). Qualquer `2xx` é sucesso; um `4xx`/`5xx` é apenas logado do lado do price-researcher — **não há retry**.

O `title` não é só o nome da trade: quando a trade **não tem supplier** e o título nomeia um bundle existente, toda linha é atribuída a ele (`BundleService::bundleByTitle()`) — é o que permite reconstruir "estes jogos são deste bundle" mesmo quando o AllKeyShop renomeia o jogo ou o bundle já saiu da janela recente. Nos demais casos vale o palpite por nome + recência. Tabela completa em [`agents/domain-map.md`](agents/domain-map.md#6-suppliers-e-trades-suppliertradetradeline--tabelas-supplierstradestrade_lines).

> **Bundle sem jogo qualificado não gera callback nenhum.** Se todos caírem pelo piso de popularidade, pelo piso de preço ou ficarem sem preço encontrado, o job termina sem chamar o endpoint, e o sistema-estoque não tem como distinguir "ainda processando" de "acabou sem resultado". Pendência registrada em [`IMPROVEMENTS.md`](IMPROVEMENTS.md). Com os critérios frouxos do bundle (`minPopularity: 1`, `minPrice: 0`, sem filtro de Gamivo) isso ficou raro, mas não impossível — bundle inteiro de jogo que o AllKeyShop não conhece volta vazio.

---

## Comportamento geral de preços

- **Fonte de popularidade:** SteamCharts (pico de jogadores nas últimas 24h)
- **Fonte de preço:** AllKeyShop (e Gamivo quando `checkGamivoOffer: true`)
- Jogos **abaixo** do `minPopularity` são descartados; `minPopularity: 0` desliga esse filtro
- Jogo sem preço encontrado não entra no resultado
- Em `/api/games/research`, jogo com preço **≤ `minPrice`** é descartado — e o `minPrice` omitido vale €0,50
- Nomes são normalizados internamente (algarismos romanos → arábicos, sufixos de edição removidos, etc.) — não é necessário tratar o nome antes de enviar

---

## Erros comuns

| HTTP | Causa provável |
|---|---|
| `400` | Body/arquivo com campos ausentes ou tipos inválidos (ver `details`) |
| `404` | Path errado — confirmar que usa `/api/games/...` e `/api/lists/...` |
| `500` com `"connect ECONNREFUSED"` | Chrome/Puppeteer travou — reiniciar o container resolve |
| `500` genérico | Erro de scraping; pode ser temporário (AllKeyShop / SteamCharts fora do ar) |

---

## Variáveis de ambiente relevantes (`.env` do price-researcher)

| Variável | Padrão | Descrição |
|---|---|---|
| `HOST_PORT` | `5555` | Porta exposta no host pelo Docker |
| `PORT` | `5555` | Porta interna do Express |
| `SERVER_TIMEOUT_MS` | `600000` (10 min) | Timeout do servidor HTTP |
| `RUN_LISTS_CONCURRENCY` | `1` | Máx. de execuções paralelas do fluxo de listas |
| `MAX_ACTIVE_LISTS` | `3` | Máx. de listas ativas por usuário processadas |
| `STEAMTRADES_PAGE_DELAY_MS` | — | Delay entre page loads do SteamTrades (throttling) |
