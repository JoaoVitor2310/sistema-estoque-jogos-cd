# Integração Fornecedores × Trades (Price Researcher)

## Contexto

O `price_researcher` navega pela Steam Trade e, quando identifica um fornecedor com trades
interessantes, registra esse fornecedor e cria a trade aqui no sistema. A aba Trades passa a
exibir o fornecedor de origem de cada trade.

Futuramente a tabela `vips` será absorvida por `suppliers` (ainda fora do escopo desta fase).

---

## Estado atual

| Tabela | Campos relevantes |
|--------|------------------|
| `suppliers` | `id`, `supplier_url`, `timestamps` |
| `trades` | `id`, `title`, `rows` (JSON), `timestamps` |
| `keys` | `supplier_id` (FK → suppliers), `supplier_url` (redundante — será removido futuramente) |

---

## Objetivo

```
price_researcher
    └─ POST /suppliers/upsert-with-trade
            ├─ cria ou atualiza o fornecedor (por steam_id)
            └─ cria a trade associada ao fornecedor
                     ↓
              aba Trades mostra o card com nome/URL do fornecedor
```

---

## Fases

### Fase 1 — Schema ✅ (em andamento)

**Migration:** `add_supplier_fields_and_supplier_id_to_trades`

Campos adicionados em `suppliers`:
- `steam_id` — string, nullable, unique (ID numérico Steam ex: `76561198...`)
- `url` — string, nullable (perfil Steam do fornecedor)
- `is_added` — boolean, default `false` (fornecedor adicionado como amigo na Steam)

Campo adicionado em `trades`:
- `supplier_id` — FK nullable → `suppliers.id`, `on delete set null`

---

### Fase 2 — Models

- `Supplier`: adicionar `steam_id`, `url`, `is_added` ao `$fillable`; relação `hasMany(Trade::class)`
- `Trade`: adicionar `supplier_id` ao `$fillable`; relação `belongsTo(Supplier::class)`

---

### Fase 3 — Endpoint para o Price Researcher

**Rota:** `POST /suppliers/upsert-with-trade`
**Middleware:** `VerifySecret` (mesmo token do `/suppliers/evaluate`)
**Controller:** `SupplierTradeController@store`
**UseCase:** `UpsertSupplierWithTradeUseCase`

Payload esperado:
```json
{
  "supplier": {
    "steam_id": "76561198xxxxxxxxx",
    "url": "https://steamcommunity.com/id/exemplo",
    "is_added": true
  },
  "trade": {
    "title": "Oferta interessante",
    "rows": [
      { "col1": "...", "col2": "..." }
    ]
  }
}
```

Resposta `201`:
```json
{
  "supplier_id": 42,
  "trade_id": 99
}
```

Lógica do UseCase:
1. `updateOrCreate` no supplier usando `steam_id` como chave
2. Criar a trade com `supplier_id` preenchido
3. Retornar IDs

---

### Fase 4 — UI (Trades.vue)

- Exibir nome/URL do fornecedor no card da trade (quando `supplier_id` presente)
- Link clicável para o perfil Steam
- Badge visual distinguindo trades manuais (sem fornecedor) de trades do price researcher

---

### Fase 5 — Futuro (fora do escopo agora)

- Absorver `vips` em `suppliers` (adicionar campos de VIP ao supplier)
- Remover `supplier_url` de `keys` (migrar para `keys.supplier_id → suppliers.url`)
- Tela de gerenciamento de fornecedores (listar, marcar `is_added`, ver histórico de trades)

---

## Progresso

- [x] Fase 1 — Migration criada
- [ ] Fase 2 — Models atualizados
- [ ] Fase 3 — Endpoint + UseCase + testes
- [ ] Fase 4 — UI Trades.vue
