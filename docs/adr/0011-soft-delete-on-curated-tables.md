# Soft-delete num conjunto curado de tabelas

Até aqui todo `delete` era físico. Um apagamento errado — uma trade, um supplier, um lote de keys — só se recuperava de backup. Passamos a usar **soft-delete** (`deleted_at`, trait `SoftDeletes` do Eloquent) como rede de proteção.

## Quais tabelas

Soft-delete **não é de graça**: quebra `unique`, desliga os `ON DELETE` do banco e passa a exigir `deleted_at` em toda query que não seja Eloquent. Só entra onde o apagamento acidental é caro de reverter:

| Tabela | Soft-delete | Motivo |
|---|---|---|
| `keys` | Sim | Registro central, histórico financeiro |
| `trades` | Sim | `keys.trade_id` referencia; recálculo de lote depende do vínculo (ADR 0004) |
| `suppliers` | Sim | `keys.supplier_id` referencia; reconstruir é trabalhoso |
| `games` | Sim | Ligado a bundles e ao price-researcher |
| `bundles` | Sim | Janela dos 21 dias, histórico de lançamento |
| `financial_months` | Sim | Livro-caixa — auditoria contábil |
| `financial_movements` | Sim | O extrato é a fonte de verdade; apagar linha some com dinheiro |
| `trade_lines` | **Não** | Apagar linha é **edição rotineira**, não evento destrutivo — e `DeleteTradeLineUseCase` decrementa a `position` das irmãs para fechar o buraco. Uma linha soft-deletada guardaria a posição antiga, que uma linha viva passaria a ocupar; o `restore()` produziria posição duplicada, quebrando a contiguidade de que "logo abaixo desta" depende |
| `bundle_games` | **Não** | Pivot puro, re-derivável, cascateia de `bundles`/`games` |
| `fees`, `assets`, `authorized_users` | **Não** | Config/lookup minúsculas; soft-delete só traria o custo do `unique` sem ganho |
| `users` | **Não** | 2 usuários, login por Google OAuth, `ProfileController::destroy` é o único caminho. Além disso `EloquentUserProvider::retrieveById` usa `newModelQuery()`, que **não** aplica o global scope — um usuário soft-deletado ainda autenticaria por sessão/remember-token. O ganho não paga essa aresta |

Não há UI de lixeira. Recuperação é manual (`Model::withTrashed()->find(...)->restore()` no tinker).

## `unique` vira índice único parcial

`unique(['col', 'deleted_at'])` **não resolve**: Postgres (prod) e SQLite (testes) tratam `NULL` como distinto, então duas linhas vivas iguais (`deleted_at` NULL nas duas) passariam. As constraints viram índices únicos **parciais** `WHERE deleted_at IS NULL` — a unicidade vale só entre as linhas vivas:

- `financial_months (year, month)` → `financial_months_year_month_active_unique`
- `suppliers (steam_id)` → `suppliers_steam_id_active_unique`

Migration: `2026_08_29_000002_partial_unique_indexes_for_soft_deleted_tables.php`. A sintaxe `CREATE UNIQUE INDEX ... WHERE` funciona nos dois bancos; MySQL não suportaria, mas o projeto não usa MySQL.

**`trades.delivery_uuid` fica com o `unique` normal, de propósito.** É UUIDv4 emitido por `DeliveryCredential::issue()` a cada trade nova e nunca reaproveitado, então uma trade soft-deletada jamais bloqueia a criação de outra — o índice parcial só adicionaria custo. E o binding da rota pública (`{trade:delivery_uuid}`) é Eloquent: trade soft-deletada devolve 404 na página de entrega, que é o comportamento desejado.

## Os `ON DELETE` do banco não disparam em soft-delete

`keys.supplier_id`/`trade_id` (`nullOnDelete`) e `financial_movements.financial_month_id` (`cascadeOnDelete`) continuam no schema para o caso de um `forceDelete`, mas um soft-delete não os aciona. Onde a intenção do cascade importa, ela foi movida para o app:

- `ReopenFinancialMonthUseCase` — ao descartar o draft corrente, soft-deleta os movimentos dele explicitamente, para não sobrar linha viva sob um mês apagado.

Nos demais casos o filho apontando para um pai soft-deletado é inofensivo: o pai some das queries (e o `restore` traz tudo de volta intacto).

`trade_lines.trade_id` é o caso interessante: `cascadeOnDelete` no schema, mas soft-deletar a trade **deixa as linhas vivas**. É inofensivo e desejado — linha só se lê por trade (`Trade::lines()`, `GET /trades/{trade}/lines`), então elas ficam inalcançáveis junto com a trade, e restaurar a trade devolve as linhas intactas de graça. Coberto em `SoftDeletesTest`.

## Query builder não enxerga o scope

`SoftDeletes` só filtra em queries **Eloquent**. `DB::table(...)` ignora. A auditoria (`grep` por `DB::table(` nas sete tabelas, mais `toBase()`/`withoutGlobalScope`/`DB::select`) encontrou um único ponto: `App\Services\Bundles\BundleService::recentBundleByGameNames` (join cru em `bundles`/`games`), que ganhou `->whereNull('bundles.deleted_at')` / `->whereNull('games.deleted_at')`. Regra daqui pra frente: query builder nessas tabelas sempre com o filtro manual.

## Somas financeiras

`FinancialMonthService::accountBalances` deriva o saldo de `$month->movements` (relação Eloquent) — o scope exclui os soft-deletados, que é o comportamento correto. Isso **amarra** a implementação: nunca trocar por `DB::table('financial_movements')->sum()`, que voltaria a contar linha apagada.

## Considered Options

- **Soft-delete em todas as tabelas** — rejeitado: `fees`/`assets`/`authorized_users`/`bundle_games`/`users` pagam o custo (índice parcial, arestas de auth) sem ganho real.
- **Coluna `is_deleted` booleana + escopo manual** — reinventa o trait do framework, sem `withTrashed`/`restore`/`forceDelete` prontos. Rejeitado.
- **Trait `SoftDeletes` no conjunto curado** — escolhido.

## Consequences

- Testes de deleção passam a usar `assertSoftDeleted` / `withTrashed`, não `assertDatabaseMissing`. Onde a asserção já era `Model::...->count()` (Eloquent), nada muda.
- `financial_months` soft-deletado não conta em `FinancialMonth::exists()` — se **todos** forem apagados, o bootstrap (`CreateDraftFinancialMonthUseCase`) volta a rodar. Aceitável.
- `forceDelete` continua disponível para expurgo real (LGPD, limpeza), agora como ato deliberado.
