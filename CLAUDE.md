# CLAUDE.md — Sistema Estoque Jogos CD

## O que é este sistema

Sistema de inventário e automação para trading de keys de jogos digitais. Registra chaves compradas, calcula lucro pelo marketplace Gamivo, gerencia bundles e executa automações via serviço externo (`price_researcher`).

## Documentação complementar

Consulte quando o contexto for relevante:

- [`docs/PRODUCT.md`](docs/PRODUCT.md) — regras de negócio e fluxos
- [`docs/PRICE_RESEARCHER.md`](docs/PRICE_RESEARCHER.md) — integração com buscador de preços próprio
- [`docs/API_GAMIVO.md`](docs/API_GAMIVO.md) — integração com o Marketplace Gamivo
- [`docs/GG_DEALS.md`](docs/GG_DEALS.md) — integração com API de dados de bundles

---

## Papel do Claude neste projeto

Atue sempre como arquiteto de software sênior com conhecimento profundo de Laravel e Clean Architecture.
- Questione decisões quando houver práticas consolidadas no mercado que apontem em outra direção
- Explique o raciocínio antes de implementar — nunca apenas execute sem contextualizar
- Nunca coloque lógica de negócio fora do Domain
- Ao sugerir onde um novo arquivo deve viver, justifique com base na camada correta
- Nomes de variáveis em inglês; português apenas em comentários
- Colunas do banco sempre em inglês e snake_case
- Mantenha boas práticas (SOLID, Clean Code, Design Patterns)
- Identifique Code Smells e proponha soluções
- Sempre escreva testes automatizados

---

## Domínios do sistema

### 1. Keys (`Key` → tabela `keys`)
Modelo central. Representa keys compradas e/ou vendidas.

Campos relevantes:
- `claim_type` — enum do tipo de problema que ocorreu na key
- `steam_id` — ID na Steam
- `game_name`, `region` — nome do jogo e região de bloqueio (ex: EU)
- `individual_cost` — custo individual da key
- `tf2_quantity` — quantidade de TF2 keys pagas pela trade
- `market_price` — preço no marketplace na data de compra
- `simulated_income` — receita líquida após taxas Gamivo
- `purchase_profit`, `purchase_profit_percent` — lucro na compra
- `sold_price`, `sale_profit`, `sale_profit_percent` — dados da venda
- `gamivo_id` — ID externo no marketplace Gamivo
- `key_code` — código da key entregue ao cliente
- `acquired_at`, `listed_at`, `sold_at`, `expires_at` — datas do ciclo de vida
- `supplier_url` — URL do perfil do fornecedor
- `min_api`, `max_api` — limites de preço aceitos pela API Gamivo

Fluxo principal:
1. Key inserida manualmente ou via importação XLSX
2. `KeyCalculationService` calcula fórmulas de lucro e preço
3. `AutoSellUseCase` sugere keys elegíveis para listagem (exclui bundles com < 21 dias)
4. `UpdateSoldOffersUseCase` atualiza com dados de venda da API Gamivo

### 2. Cálculo de lucro (`KeyCalculationService` + `Domain/Pricing`)

Gamivo tem 2 tiers de taxa:

| Tier | Condição | Fórmula |
|------|----------|---------|
| Baixo | `market_price < €8` | `price × (1 - 0.072) - 0.11` |
| Alto | `market_price ≥ €8` | `price × (1 - 0.102) - 0.55` |

`min_api` = `individual_cost × 1.4–1.6` (tier por faixa de preço); `max_api` = `individual_cost × 8–30`.

### 3. Bundles
Agrupamento de jogos (`bundle` ou `choice`). Many-to-many com `Game` via `bundle_games`.

**Regra dos 21 dias**: keys de jogos em bundles lançados há menos de 21 dias são excluídas do `autoSell()`.

### 4. Assets (`Asset` → tabela `assets`)
Representa ativos de troca (ex: TF2 key). Campos: `price_euro`, `price_dollar`, `price_brl`.
Usado por `KeyCalculationService` para converter o custo da trade em euros.

### 5. Fees (`Fee` → tabela `fees`)
Taxas do marketplace. Campos: `name`, `preco`.
Chaves usadas: `gamivoPercentualMenor`, `gamivoFixoMenor`, `gamivoPercentualMaior`, `gamivoFixoMaior`.

### 6. VIPs e automação
- `Vip` — cliente VIP com `id_steam`
- `VipList` — execução de lista (status: `queued` | `completed` | `failed`)
- Fluxo: `ExecuteVipListUseCase` → POST `price_researcher` → webhook callback → `VipListExecutionService::applyCallback()`

### 7. Autorização
- `AuthorizedUsers` — controla acesso (`can-edit`)
- Admin: `Gate::define('is-admin', fn($u) => $u->email === env('ADMIN_EMAIL'))`

---

## Arquitetura

**Clean Architecture podada** — domínio isolado e testável, sem boilerplate de repositories abstratos ou adapters. Sistema interno com dois usuários; nunca precisaremos trocar o framework.

### Princípio central

| Camada | Responsabilidade |
|--------|-----------------|
| **Controller** | Recebe HTTP, delega para UseCase ou Service. Sem lógica. |
| **UseCase** | Orquestra workflows multi-step. Um UseCase = uma operação completa. |
| **Service** | Acessa infraestrutura (Eloquent, APIs, cache). Sem regras de negócio. |
| **Domain** | PHP puro. Sem Eloquent, sem framework. Recebe primitivos/VOs, retorna resultados. |

### Quando usar UseCase vs Service direto

| Situação | Caminho |
|----------|---------|
| Workflow multi-step (cruza domínios) | Controller → UseCase → Services + Domain |
| CRUD simples | Controller → Service |
| Regra de negócio pura | Domain direto |

### Wrappers privados — regra

Só crie um método privado se ele: (a) é chamado em 3+ lugares, (b) revela intenção que a implementação esconde, ou (c) encapsula variação independente. Caso contrário, inline.

```php
// ❌ Wrapper sem valor
private function convertExcelDate($cell): ?string {
    return ExcelDateConverter::convert($cell->getValue()) ?? now()->toDateString();
}

// ✅ Inline
ExcelDateConverter::convert($cell->getValue()) ?? now()->toDateString()
```

### Value Objects — quando usar

Usar quando uma função receberia 3+ parâmetros do mesmo conceito ou os dados vêm de fonte externa e precisam de validação (ex: taxas do banco → `MarketplaceFee`). Não usar para 1-2 primitivos simples.

### Estrutura de arquivos

```
app/
├── Domain/
│   ├── Pricing/
│   │   ├── ProfitCalculator.php
│   │   ├── IncomeCalculator.php
│   │   ├── SalePriceCalculator.php
│   │   ├── MinMaxPriceCalculator.php
│   │   └── ValueObjects/MarketplaceFee.php
│   ├── Keys/
│   │   ├── KeyEligibility.php          # regra dos 21 dias
│   │   └── KeyPriceAging.php           # degradação de preço por tempo na prateleira
│   ├── Platform/
│   │   └── PlatformIdentifier.php      # regex Steam, EA, EGS, GOG, Xbox, PSN
│   ├── Import/
│   │   ├── ExcelDateConverter.php
│   │   ├── ImportRowValidator.php
│   │   └── ImportHeaderValidator.php
│   ├── Bundles/
│   │   └── BundleTypeResolver.php
│   └── Enums/
│       ├── Marketplace.php             # apenas Gamivo por enquanto
│       ├── KeyPlatform.php
│       ├── ClaimType.php
│       ├── KeyFormat.php
│       └── SellPlatform.php
│
├── UseCases/
│   ├── Keys/
│   │   ├── RegisterKeyUseCase.php
│   │   ├── UpdateKeyUseCase.php
│   │   ├── ImportKeysFromXlsxUseCase.php
│   │   ├── AutoSellUseCase.php
│   │   └── UpdateSoldOffersUseCase.php
│   ├── Bundles/
│   │   └── SyncBundlesFromApiUseCase.php
│   └── Vips/
│       └── ExecuteVipListUseCase.php
│
├── Services/
│   ├── Keys/
│   │   ├── KeyCalculationService.php   # taxas com cache, conversão para VOs
│   │   └── KeyRepository.php           # queries complexas
│   ├── Games/GameService.php
│   ├── Bundles/BundleService.php
│   ├── Suppliers/SupplierService.php
│   ├── Vips/VipListExecutionService.php
│   ├── ResourceService.php             # conversão de moedas para Assets
│   └── External/CurrencyConversionService.php
│
├── Http/
│   ├── Controllers/
│   │   ├── Keys/
│   │   │   ├── KeyController.php       # CRUD — rota: GET/POST/PUT/DELETE /keys
│   │   │   ├── KeyImportController.php
│   │   │   └── KeySaleController.php   # autoSell, whenToSell, updateSoldOffers...
│   │   ├── GameController.php
│   │   ├── BundleController.php
│   │   ├── AssetController.php
│   │   ├── FeeController.php
│   │   └── VipController.php
│   └── Requests/
│
└── Models/                             # Eloquent puro — sem lógica de negócio
    ├── Key.php         → keys
    ├── Game.php        → games
    ├── Bundle.php      → bundles
    ├── Supplier.php    → suppliers
    ├── Asset.php       → assets
    ├── Fee.php         → fees
    └── Vip.php / VipList.php
```

---

## Roadmap

### Próximo — CI/CD com GitHub Actions

- [ ] Criar `.github/workflows/ci.yml`: PHP 8.3, `composer install`, Pint, PHPStan, Pest, Codecov
- [ ] Instalar PHPStan + Larastan (`composer require --dev nunomaduro/larastan`)
- [ ] `phpstan.neon`: `app/Domain/` nível 8, restante nível 5

### Futura — Normalizar FK entre `keys` e `games`

Hoje o vínculo é por string: `keys.gamivo_id ←→ games.id_gamivo`. Não há integridade referencial, JOINs são em varchar e `game_name`/`region` ficam duplicados.

**Estratégia Expand-Contract:**
1. Migration EXPAND: adicionar `game_id` (bigint nullable, FK → `games.id`) em `keys`
2. Migration MIGRATE: backfill via `gamivo_id → id_gamivo`
3. Ajustar `RegisterKeyUseCase` para persistir `game_id`
4. Auditar keys órfãs → tornar NOT NULL
5. Reescrever `game()` para `belongsTo(Game::class)` padrão
6. Reescrever `scopeWithoutRecentBundle` com FK integer
7. Avaliar remoção de `game_name`/`region` de `keys` (dados denormalizados)
8. Migration CONTRACT: remover `gamivo_id` de `keys`

---

## Regras de negócio

- **Regra dos 21 dias**: keys de jogos em bundles com < 21 dias são excluídas do `autoSell()`.
- **Tiers Gamivo**: fee diferente abaixo e acima de €8 (ver tabela na seção Domínios).
- **`min_api`/`max_api`**: calculados em `MinMaxPriceCalculator` com base no `individual_cost`.
- **`individual_cost` é imutável após registro**: no `UpdateKeyUseCase` nunca é recalculado.
- **Importação XLSX**: 10 colunas obrigatórias (A=Data, B=Preço mercado, C=URL perfil, D=Qtd. TF2, E=Bundle, F=Expiração, G=Popularidade, H=Region Lock, I=Chave, J=Nome do Jogo). Datas em formato serial do Excel são convertidas. `tf2_quantity = 0` é rejeitado.

---

## Variáveis de ambiente

```env
API_PRICE_RESEARCHER=
DEV_API_PRICE_RESEARCHER=
CARCA_API_GAMIVO=
SISTEMA_ESTOQUE_BASE_URL=
DEV_SISTEMA_ESTOQUE_BASE_URL=

GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=

ADMIN_EMAIL=carcadeals@gmail.com
EXTERNAL_SECRET=
```
