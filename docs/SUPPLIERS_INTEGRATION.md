# Suppliers — Integração Price Researcher ↔ Sistema Estoque

Este documento é **compartilhado** entre os dois sistemas mas tem **seções de propriedade separada**:

- A seção [Price Researcher](#price-researcher) é mantida pelo **price-cd**. O Sistema Estoque pode consultá-la para entender o que o price espera, mas não deve editá-la.
- A seção [Sistema Estoque](#sistema-estoque) é mantida pelo **Sistema Estoque**. O price-cd pode consultá-la para entender o contrato implementado, mas não deve editá-la.

---

## Price Researcher

> **Proprietário:** price-cd (`\\wsl.localhost\ubuntu-24.04\var\www\price-cd`)
> Não edite esta seção se você é do Sistema Estoque.

### O que o price-cd faz

O price-cd varre as páginas de listagem do SteamTrades em busca de fornecedores potenciais. Para cada tópico encontrado:

1. Extrai o `topic_code` (ex.: `G0eXM`) e a lista de jogos da seção `.have`.
2. Busca popularidade no SteamCharts e preço no Gamivo/AllKeyShop.
3. Envia os dados ao Sistema Estoque via `POST /suppliers/prospect`.
4. Aplica a regra de negócio com base na resposta para decidir se comenta.

### Regra de negócio: quando comentar

O price-cd comenta em um tópico se **todas** as condições abaixo forem verdadeiras:

- Há jogos rentáveis (`profitable.length > 0`)
- **E** pelo menos uma das seguintes:
  - Os jogos mudaram desde o último comentário (`games_changed === true`)
  - Nunca comentamos neste tópico (`last_commented_at === null`)
  - Já faz 2 semanas ou mais desde o último comentário

```typescript
const TWO_WEEKS_MS = 14 * 24 * 60 * 60 * 1000;
const isStale = !result.last_commented_at ||
  Date.now() - new Date(result.last_commented_at).getTime() >= TWO_WEEKS_MS;
const shouldComment = profitableGames.length > 0 && (result.games_changed || isStale);
```

### O que o price-cd precisa do Sistema Estoque

Para implementar a regra acima, o price-cd depende das seguintes entregas do Sistema Estoque:

#### 1. Novo campo `topic_code` no payload aceito pelo `POST /suppliers/prospect`

O price-cd passará a enviar `topic_code` em todas as chamadas. O Sistema Estoque deve armazená-lo vinculado ao registro da trade/interação.

#### 2. Dois novos campos na resposta do `POST /suppliers/prospect`


| Campo               | Tipo            | O que o price-cd espera                                                                                     |
| ------------------- | --------------- | ----------------------------------------------------------------------------------------------------------- |
| `last_commented_at` | `string | null` | ISO 8601 do último comentário registrado para este `topic_code`. `null` se nunca comentamos neste tópico    |
| `games_changed`     | `boolean`       | `true` se a lista de nomes de jogos atual difere da lista salva no último comentário para este `topic_code` |


#### 3. Contrato completo esperado

**Payload que o price-cd envia:**

```json
{
  "supplier": {
    "steam_id": "76561198xxxxxxxxx",
    "url": "https://steamcommunity.com/profiles/76561198xxxxxxxxx"
  },
  "topic_code": "G0eXM",
  "games": [
    { "name": "Game X", "price_euro": 4.99, "popularity": 1200, "region": null }
  ]
}
```

**Resposta que o price-cd espera:**

```json
{
  "profitable": [
    { "name": "Game X", "price_euro": 4.99, "popularity": 1200, "region": null, "tf2_price": 0.45 }
  ],
  "is_added": false,
  "last_commented_at": "2026-06-10T14:30:00Z",
  "games_changed": true
}
```

### Impacto no price-cd após o Sistema Estoque entregar

Quando o Sistema Estoque implementar os campos acima, o price-cd irá:

- Remover a segunda navegação por tópico (atualmente navega até `/search?page=last` para buscar nosso último comentário via scraping — isso some completamente)
- Remover `hasRecentComment` de `TopicData` e do scraper
- Adicionar `topic_code` ao `SupplierInput`
- Adicionar `last_commented_at` e `games_changed` ao `ProspectResult`
- Mover a lógica de decisão descrita acima para o use case

---

## Sistema Estoque

> **Proprietário:** Sistema Estoque
> Não edite esta seção se você é do price-cd.

---

### Estado atual do schema

| Tabela | Campos relevantes |
|--------|------------------|
| `suppliers` | `id`, `steam_id` (nullable, unique), `url` (nullable), `is_added` (bool, default `false`), `has_traded` (bool, default `false`), `timestamps` |
| `trades` | `id`, `title`, `rows` (JSON), `supplier_id` (FK nullable → `suppliers.id`, on delete set null), `timestamps` |
| `keys` | `supplier_id` (FK → `suppliers.id`, NOT NULL), `supplier_url` (redundante — remoção planejada na Fase 6) |

**Endpoint ativo:** `POST /suppliers/prospect` → `SupplierProspectController@store` (Bearer via `EXTERNAL_SECRET`)

**Payload atual:**
```json
{
  "supplier": { "steam_id": "76561198xxxxxxxxx", "url": "https://steamcommunity.com/profiles/..." },
  "games": [{ "name": "Game X", "price_euro": 4.99, "popularity": 1200, "region": null }]
}
```

**Resposta atual:**
```json
{
  "profitable": [{ "name": "Game X", "price_euro": 4.99, "popularity": 1200, "region": null, "tf2_price": 0.45 }],
  "is_added": false
}
```

---

### Fases planejadas

#### Fase 4 — Suporte a `list_code` e `last_commented_at` (próxima entrega)

O price-cd precisa saber se já comentamos no tópico (`last_commented_at`) e se os jogos mudaram desde então (`games_changed`).

**Decisão de modelagem:** dois novos campos em `trades`, nenhuma tabela nova.

- `list_code` — string, nullable. Código gerado pelo SteamTrades que identifica unicamente um tópico de listagem (ex.: `"G0eXM"`). O Sistema Estoque apenas armazena o valor para saber com qual tópico específico uma trade está associada — não o gera nem o interpreta. Nullable porque trades criadas manualmente (jogos avulsos) não têm lista associada. Nome genérico escolhido intencionalmente: cobre tópicos de fornecedor agora e listas VIP quando `VipList` for absorvido em `trades` (Fase 8).
- `last_commented_at` — timestamp, nullable. Setado **explicitamente** quando o price-cd efetivamente comenta no tópico — distinto do `created_at` da trade (que marca apenas quando a análise foi feita). Trades sem comentário ficam com `null`.

**`games_changed`** não requer coluna: o UseCase busca a trade mais recente com o mesmo `list_code` onde `last_commented_at IS NOT NULL`, compara os nomes dos jogos em `rows` com a lista do request atual e deriva o boolean na hora.

**Migration:** adicionar em `trades`:
- `list_code` — string, nullable
- `last_commented_at` — timestamp, nullable

**Mudanças de código:**

1. Migration com `list_code` e `last_commented_at` em `trades`
2. `Trade`: adicionar os dois campos ao `$fillable`; cast de `last_commented_at` como `datetime`
3. `EvaluateSupplierProspectRequest`: aceitar `list_code` (string, nullable)
4. `ProspectSupplierUseCase`:
   - Antes de criar a trade, buscar a trade mais recente com o mesmo `list_code` onde `last_commented_at IS NOT NULL` → derivar `last_commented_at` (retorno) e `games_changed`
   - Ao criar a `Trade`, persistir `list_code` se enviado
5. Atualizar resposta com os dois novos campos

**Payload após implementação:**
```json
{
  "supplier": { "steam_id": "...", "url": "..." },
  "list_code": "G0eXM",
  "games": [...]
}
```

**Resposta após implementação:**
```json
{
  "profitable": [...],
  "is_added": false,
  "last_commented_at": "2026-06-10T14:30:00Z",
  "games_changed": true
}
```

---

#### Fase 5 — Tela de gerenciamento de fornecedores (UI)

- Listar suppliers com `has_traded`, `is_added`, última trade associada
- Permitir marcar `is_added` manualmente (quando o usuário adiciona o fornecedor no Steam)
- Exibir `supplier_id` no card de Trade na aba Trades (hoje o campo existe no banco mas não é exibido)

---

#### Fase 6 — Remover `supplier_url` de `keys`

Hoje `keys.supplier_url` é redundante: o vínculo real é via `keys.supplier_id → suppliers.id → suppliers.url`.

Estratégia Expand-Contract:
1. Garantir que todos os `keys.supplier_id` estejam preenchidos corretamente
2. Remover leituras/escritas de `supplier_url` do código (KeyController, RegisterKeyUseCase, ImportKeysFromXlsxUseCase)
3. Migration `dropColumn('supplier_url')` em `keys`

---

#### Fase 7 — Normalizar `marketPriceRaw` em `trades.rows`

Rows antigas armazenam `marketPriceRaw` como string com vírgula (`"5,78"`) por herança do TSV colado manualmente. O correto é ponto decimal (`"5.78"`) — vírgula é formatação de display.

Migration: ler todos os `rows`, converter vírgula → ponto em `marketPriceRaw` e `tf2Qty`, salvar. O frontend já faz `replace(',', '.')` antes de `parseFloat`, então não quebra durante a transição.

---

#### Fase 8 — Absorver `VipList` em `trades` e `vips` em `suppliers`

Hoje `Vip`, `VipList` e `Supplier` são entidades separadas, mas representam o mesmo conceito: um fornecedor Steam com uma lista de jogos.

**`VipList` → `trades`:**
- Uma execução de lista VIP é estruturalmente idêntica a uma trade: tem `rows` (jogos), um responsável (o VIP = supplier) e uma origem (o tópico do SteamTrades = `list_code`)
- Migração: cada `VipList` vira uma `Trade` com `list_code` preenchido e `supplier_id` apontando para o supplier correspondente
- `VipListExecutionService`, `ExecuteVipListUseCase` e a tabela `vip_lists` são removidos após a migração

**`Vip` → `suppliers`:**
- `Vip.id_steam` já é coberto por `suppliers.steam_id`
- Campos de VIP (flags, histórico) migram para `suppliers`
- Tabela `vips` removida após migração

Escopo detalhado a definir antes de implementar.