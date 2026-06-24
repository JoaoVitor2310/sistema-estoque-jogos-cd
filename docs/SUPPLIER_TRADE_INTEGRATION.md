# Integração Fornecedores (Price Researcher)

## Contexto

O `price_researcher` navega pela Steam Trade e, quando identifica um fornecedor com jogos
interessantes, chama o sistema para avaliar rentabilidade e registrar/atualizar o fornecedor.
Com base no campo `is_tradable` da resposta, o price decide se deve ou não comentar no tópico.

Futuramente a tabela `vips` será absorvida por `suppliers` (ainda fora do escopo desta fase).

---

## Estado atual

| Tabela | Campos relevantes |
|--------|------------------|
| `suppliers` | `id`, `supplier_url`, `steam_id`, `url`, `is_added`, `timestamps` |
| `trades` | `id`, `title`, `rows` (JSON), `supplier_id` (FK nullable), `timestamps` |
| `keys` | `supplier_id` (FK → suppliers), `supplier_url` (redundante — será removido futuramente) |

---

## Objetivo

```
price_researcher
    └─ POST /suppliers/prospect
            ├─ upsert do fornecedor (por steam_id)
            ├─ avalia rentabilidade dos jogos
            └─ retorna profitable[]
                     ↓
              price decide se comenta no tópico (responsabilidade dele)
```

---

## Fases

### Fase 1 — Schema ✅

**Migration:** `add_supplier_fields_and_supplier_id_to_trades`

Campos adicionados em `suppliers`:
- `steam_id` — string, nullable, unique (ID numérico Steam ex: `76561198...`)
- `url` — string, nullable (perfil Steam do fornecedor)
- `is_added` — boolean, default `false` (fornecedor adicionado como amigo na Steam)
- `has_traded` — boolean, default `false` (já negociamos ao menos uma vez; prospects ficam `false`)

Campo adicionado em `trades`:
- `supplier_id` — FK nullable → `suppliers.id`, `on delete set null`

---

### Fase 2 — Models ✅

- `Supplier`: `steam_id`, `url`, `is_added` no `$fillable`; relação `hasMany(Trade::class)`
- `Trade`: `supplier_id` no `$fillable`; relação `belongsTo(Supplier::class)`

---

### Fase 3 — Endpoint

**Rota:** `POST /suppliers/prospect` (`SupplierEvaluationController` renomeado para `SupplierController`)
**Middleware:** `VerifySecret`

Payload esperado:
```json
{
  "supplier": {
    "steam_id": "76561198xxxxxxxxx",
    "url": "https://steamcommunity.com/id/exemplo",
    "is_added": true
  },
  "games": [
    { "name": "Game X", "price_euro": 4.99, "popularity": 1200, "region": null }
  ]
}
```

Resposta `200`:
```json
{
  "profitable": [
    { "name": "Game X", "price_euro": 4.99, "popularity": 1200, "region": null, "tf2_price": 0.45 }
  ],
  "is_added": false
}
```

> `is_added` é lido do banco — o price researcher **não envia** esse campo, apenas lê na resposta
> para saber se precisa adicionar o fornecedor no Steam.

Lógica:
1. `updateOrCreate` no supplier usando `steam_id` como chave
2. Rodar `EvaluateSupplierProfitabilityUseCase` com os games
3. Retornar `profitable` + `is_added` (do registro do supplier)

---

### Fase 4 — Futuro (fora do escopo agora)

- Absorver `vips` em `suppliers` (adicionar campos de VIP ao supplier)
- Remover `supplier_url` de `keys` (migrar para `keys.supplier_id → suppliers.url`)
- Tela de gerenciamento de fornecedores (listar, marcar `is_added`, ver histórico de trades)
- Vincular trades a fornecedores via `supplier_id`
- **Normalizar `marketPriceRaw` em `trades.rows`**: hoje é string com vírgula (`"5,78"`) por herança do TSV colado pelo usuário. Deveria ser número com ponto (`5.78`) — vírgula é formatação de display e não pertence ao dado armazenado. Migração: ler todos os `rows`, converter vírgula → ponto em `marketPriceRaw` e `tf2Qty`, salvar como string numérica. Frontend já faz `replace(',', '.')` antes de `parseFloat`, então suporta ambos sem quebrar.

---

## Progresso

- [x] Fase 1 — Schema
- [x] Fase 2 — Models
- [x] Fase 3 — Endpoint + testes
