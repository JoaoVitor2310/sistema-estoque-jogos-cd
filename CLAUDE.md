# CLAUDE.md — Sistema Estoque Jogos CD

## O que é este sistema

Sistema de inventário e automação para trading de keys de jogos digitais. Registra chaves compradas, calcula lucro por marketplace (G2A, Gamivo, Kinguin), gerencia bundles e executa automações via serviço externo (`price_researcher`).

## Documentação complementar

Antes de implementar, consulte os documentos abaixo quando o contexto for relevante:

- [`docs/PRODUCT.md`](docs/PRODUCT.md) — visão geral das regras de negócio e fluxos para maximizar lucros
- [`docs/PRICE_RESEARCHER.md`](docs/PRICE_RESEARCHER.md) — integração com buscador de preços próprio
- [`docs/API_GAMIVO.md`](docs/API_GAMIVO.md) — integração com servidor que se comunica com Marketplace Gamivo
- [`docs/GG_DEALS.md`](docs/GG_DEALS.md) — integração com api externa de dados de bundles

## Papel do Claude neste projeto

Atue sempre como arquiteto de software sênior com conhecimento profundo de Laravel e Clean Architecture.
- Questione decisões quando houver práticas consolidadas no mercado que apontem em outra direção
- Proponha soluções que o Laravel oferece, sempre respeitando as camadas de arquitetura a ser seguida
- Explique o raciocínio antes de implementar — nunca apenas execute sem contextualizar
- Quando o Laravel oferecer algo relevante, apresente o que ele resolve, onde vive nas camadas e qual o custo de usá-lo
- Nunca coloque lógica de negócio fora do Domain
- Ao sugerir onde um novo arquivo deve viver, justifique com base na camada correta da arquitetura
- Nomes de variáveis dentro do código sempre em inglês, utilize português somente em comentários
- Nomes de colunas do banco de dados sempre em inglês e snake_case — ex: `key_format`, `claim_type`, `sell_platform`. Nunca criar colunas com nomes em português ou misturados
- Não comente nada sobre decisões futuras
- Mantenha sempre boas práticas (Design Patterns, Clean Code, SOLID, etc)
- Identifique possíveis Code Smells, alerte e proponha soluções.
- Sempre escreva testes automatizados para cada parte do sistema

---

## Domínios do sistema

### 1. Keys (`Venda_chave_troca`)
Modelo central. Representa keys compradas e/ou vendidas.

Campos relevantes:
- `tipo_reclamacao_id` - id do problema que deu na key, é importante para saber qual problema deu e agrupar
- `steamId` - id na steam, plataforma que vende os jogos oficiais
- `game_name`, `region` - nome do jogo, região que ele está limitado (EU = Europa por exemplo)
- `individual_cost` — custo individual da key
- `tf2_quantity` — custo da trade na qual aquela key pertence
- `market_price` — preço no marketplace na data de compra
- `simulated_income` — receita líquida após taxas
- `purchase_profit`, `purchase_profit_percent` — lucro na compra (valor absoluto e percentual)
- `sold_price`, `sale_profit`, `sale_profit_percent` — valor absoluto na venda e lucro (absoluto e percentual)
- `gamivo_id`, `idSteamcharts` — IDs externos para automação
- `key_code` — código da key para enviar ao cliente
- `acquired_at` — data que adquiriu a key
- `listed_at` — data que botou o jogo para vender
- `sold_at` — data que vendeu a key
- `expires_at` — data que a key se torna inválida (deve vender antes)
- `supplier_url` — url do fornecedor que vendeu a key
- `minApiGamivo`, `maxApiGamivo` — valores mínimos e máximos que a API Gamivo pode chegar (já descontando a taxa)

Fluxo principal:
1. Key é inserida manualmente ou via importação XLSX
2. `KeyCalculationService` calcula fórmulas de lucro e preço
3. `AutoSellUseCase` sugere keys prontas para listagem/vender (exclui jogos em bundles recentes, < 21 dias)
4. `UpdateSoldOffersUseCase` atualiza com dados de venda (`sold_price`, `sale_profit`, `sale_profit_percent`, `sold_at`)

### 2. Cálculo de lucro (`KeyCalculationService` + Domain/Pricing)
Gamivo tem 2 tiers de taxa:

| Marketplace | Fórmula simplificada |
|-------------|----------------------|
| Gamivo      | `market_price × (1 - %fee) - fee_fixo` (2 tiers: < €8 e ≥ €8) |

### 3. Bundles
Agrupamento de jogos (tipo `bundle` ou `choice`). Relacionamento many-to-many com `Game` via `bundle_games`. A tabela pivot armazena `bundle_launch_price`.

A regra dos 21 dias usa a `bundle_games.created_at` para excluir lançamentos recentes do `autoSell()`.

### 4. VIPs e automação
- `Vip` representa um cliente VIP com `id_steam`
- `VipList` representa uma execução de lista para aquele VIP (status: `queued` | `completed` | `failed`)
- Fluxo: controller chama `ExecuteVipListUseCase` → HTTP POST para `price_researcher` → `price_researcher` chama webhook de callback → `VipListExecutionService::applyCallback()` persiste resultado

### 5. Autorização
- `AuthorizedUsers` — tabela que controla quem pode acessar (`can-edit`)
- Admin via `env('ADMIN_EMAIL')`: `Gate::define('is-admin', fn($u) => $u->email === env('ADMIN_EMAIL'))`

---

## Problemas identificados

### Críticos (risco em produção)

**4. N+1 em `GameService::searchGamesIdSteam()`**
```php
$games = Game::whereNull('id_steamcharts')->get(); // todos de uma vez
foreach ($games as $game) {
    // 1 chamada HTTP externa por jogo
    // 1 UPDATE por jogo
}
```
Para muitos jogos, isso trava o processo.

### Moderados (qualidade e manutenção)

**8. `GameController::store()` busca o game duas vezes**
```php
$created = Game::create($data);
// ...
return Game::select('*')->where('id', $created->id)->with(['bundles'])->first();
```
Poderia usar `$created->load('bundles')`.

**11. Nome do modelo inconsistente**
- Modelo: `Venda_chave_troca` (snake_case com maiúscula, fora do padrão Laravel)
- Tabela: `venda_chave_trocas`
- Rota: `/venda-chave-troca`

**12. `tipo_reclamacao_id` com validação `min:1 max:4` hardcoded**
Se novos tipos forem cadastrados, a validação quebra sem alterar o código.

### Menores (débito técnico)

**13. Queue driver: database**
Jobs usam a tabela do banco como fila. Sob carga, pode gerar lock contention. Redis seria mais robusto.

**14. Campos depreciados no modelo Vip**
`first_link`, `second_link`, `third_link`, `steam_link` — marcados como deprecated na migration mas ainda no modelo. Remover esses campos.

**15. Sem paginação em `searchPopularity()`**
Carrega todos os jogos com `id_steamcharts` não nulo de uma vez.

**16. `searchGamesIdSteam()` não distingue "não buscado" de "não encontrado"**
`id_steamcharts IS NULL` significa tanto "nunca foi buscado" quanto "foi buscado mas o jogo não existe no Steamcharts". Resultado: o cron re-processa indefinidamente os jogos que já se sabe que não existem, fazendo a lista crescer e gerando requisições desnecessárias ao price_researcher.
Solução: adicionar coluna `steamcharts_searched_at TIMESTAMP NULL` na tabela `games`. Após cada tentativa de busca (com ou sem resultado), preencher com `now()`. O query do cron passa a filtrar `whereNull('id_steamcharts')->whereNull('steamcharts_searched_at')`, zerando as buscas repetidas.

---

## Arquitetura: Laravel Modular + Domain Layer leve

### Contexto de decisão

Este sistema é uma ferramenta **operacional interna** com:
- Dois usuário apenas (operadores das keys)
- Volume de dados moderado (keys, bundles, VIPs)
- Cálculos financeiros críticos que não podem errar
- Integrações com serviços externos (price_researcher, Gamivo)
- Sem necessidade de escala horizontal

**Clean Architecture completa não é indicada.** Repositories abstratos e Adapters adicionariam ~20-30 arquivos de boilerplate sem benefício real. Nunca vamos trocar o Laravel, e o sistema tem ~10.8k LOC (5k PHP backend + 4.7k Vue frontend + 1k migrations).

**O que adotamos:** Clean Architecture podada — mantém o que importa (domínio isolado e testável, use cases para workflows complexos), descarta o que não interessa (interfaces de repository, adapters). A camada `Domain/` é PHP puro (sem Eloquent, sem framework). Use Cases orquestram workflows multi-step. Services lidam com infraestrutura (banco, APIs, cache). Controllers só recebem HTTP.

### Princípio central

> **Controllers** recebem HTTP e delegam para **UseCases** (workflows complexos) ou **Services** (operações simples).
> **UseCases** orquestram o fluxo — chamam Services para infra e Domain para regras. Um UseCase = uma operação de negócio completa.
> **Services** acessam infraestrutura (Eloquent, APIs externas, cache). Não contêm regras de negócio.
> **Domain** é PHP puro — recebe valores primitivos e Value Objects, retorna resultados. Zero dependência do Laravel.

### Wrappers desnecessários

Antes de criar um método privado que apenas repassa chamadas, pergunte: **ele adiciona nome semântico, lógica própria ou abstrai múltiplos callers?** Se não, faça inline.

Um wrapper só se justifica quando:
- É chamado em 3+ lugares com lógica não trivial
- O nome revela uma intenção que a implementação não deixa clara
- Encapsula uma variação que pode mudar independentemente

Exemplos do que **não** fazer:
```php
// ❌ Wrapper sem valor — apenas repassa, sem semântica nova
private function convertExcelDate($cell): ?string {
    return ExcelDateConverter::convert($cell->getValue()) ?? now()->toDateString();
}

// ✅ Inline — explícito, legível, sem indireção desnecessária
ExcelDateConverter::convert($cell->getValue()) ?? now()->toDateString()
```

### Quando usar UseCase vs Service direto

| Situação | Caminho | Exemplo |
|----------|---------|---------|
| Workflow multi-step que cruza domínios | Controller → UseCase → Services + Domain | Registrar key (10+ passos) |
| Operação simples / CRUD | Controller → Service | Deletar key, buscar por ID |
| Regra de negócio pura | Qualquer camada → Domain | Calcular lucro, verificar elegibilidade |

### Estrutura atual

```
app/
├── Domain/                                    # PHP PURO — zero dependência do Laravel
│   ├── Pricing/                               # Cálculos financeiros
│   │   ├── ProfitCalculator.php               # Lucro real, percentual, venda
│   │   ├── IncomeCalculator.php               # Income simulado por marketplace
│   │   ├── SalePriceCalculator.php            # Preço mínimo de venda e rótulo de custo
│   │   ├── MinMaxPriceCalculator.php          # Min/max API Gamivo
│   │   └── ValueObjects/
│   │       └── MarketplaceFee.php             # VO: taxas por marketplace (gamivo tiers)
│   │
│   ├── Keys/                                  # Regras do ciclo de vida das keys
│   │   ├── KeyEligibility.php                 # Regra dos 21 dias, elegibilidade para venda
│   │   └── KeyPriceAging.php                  # Degradação de preço por idade (limbo, 12/9/6/3 meses)
│   │
│   ├── Platform/                              # Identificação de plataforma
│   │   └── PlatformIdentifier.php             # Regex para Steam, EA, EGS, GOG, Xbox, PSN
│   │
│   ├── Import/                                # Regras de importação XLSX
│   │   ├── ExcelDateConverter.php             # Conversão de datas seriais do Excel
│   │   ├── ImportRowValidator.php             # Validação de cada linha
│   │   └── ImportHeaderValidator.php          # Validação de cabeçalhos
│   │
│   ├── Bundles/                               # Regras de bundles
│   │   └── BundleTypeResolver.php             # Determina se é "choice" ou "bundle"
│   │
│   └── Enums/                                 # Enums compartilhados
│       ├── Marketplace.php                    # Gamivo(3) — G2A e Kinguin removidos
│       └── KeyPlatform.php                    # Steam, EA, EGS, GOG, Xbox, PSN, Desconhecido
│
├── UseCases/                                  # ORQUESTRAÇÃO de workflows complexos
│   ├── Keys/
│   │   ├── RegisterKeyUseCase.php             # store(): cálculos + fornecedor + plataforma + persistência
│   │   ├── UpdateKeyUseCase.php               # update(): recalcula + atualiza fornecedor
│   │   ├── ImportKeysFromXlsxUseCase.php      # Validação XLSX + registro em batch
│   │   ├── AutoSellUseCase.php                # Busca keys elegíveis para listagem
│   │   └── UpdateSoldOffersUseCase.php        # Atualiza keys vendidas + cálculo de lucro de venda
│   ├── Bundles/
│   │   └── SyncBundlesFromApiUseCase.php      # Fetch API GGDeals + criar bundles + associar jogos
│   └── Vips/
│       └── ExecuteVipListUseCase.php          # Validar + criar VipList + chamar price_researcher
│
├── Services/                                  # INFRAESTRUTURA — banco, APIs, cache
│   ├── Keys/
│   │   ├── KeyCalculationService.php          # Carrega taxas (com cache), converte para VOs
│   │   └── KeyRepository.php                  # Queries complexas (autoSell, limbo, sold)
│   ├── Games/
│   │   └── GameService.php                    # Lookup Gamivo, Steam ID, popularity, CRUD
│   ├── Bundles/
│   │   └── BundleService.php                  # Consulta/filtros de bundles
│   ├── Suppliers/
│   │   └── SupplierService.php                # findOrCreate de fornecedor
│   ├── Vips/
│   │   └── VipListExecutionService.php        # applyCallback() — persiste resultado do webhook
│   └── External/
│       └── CurrencyConversionService.php      # API AwesomeAPI
│
├── Http/
│   ├── Controllers/
│   │   ├── Keys/
│   │   │   ├── KeyController.php              # CRUD
│   │   │   ├── KeyImportController.php        # import XLSX, downloadExample
│   │   │   └── KeySaleController.php          # autoSell, whenToSell, updateSoldOffers, etc.
│   │   ├── Games/
│   │   │   └── GameController.php
│   │   ├── Bundles/
│   │   │   └── BundleController.php
│   │   └── Vips/
│   │       └── VipController.php
│   └── Requests/
│
└── Models/                                    # Eloquent puro — sem lógica de negócio
```

### Fluxo de dados entre camadas

```
Controller                          UseCase                              Domain
    │                                  │                                   │
    │  $request->validated()           │                                   │
    ├──────────────────────────────────►│                                   │
    │  (array de primitivos)           │                                   │
    │                                  │  Service carrega dados do banco   │
    │                                  │  e converte para Value Objects    │
    │                                  │         │                         │
    │                                  │         ▼                         │
    │                                  │  MarketplaceFee::fromArray([...]) │
    │                                  │         │                         │
    │                                  │         │  VOs + primitivos       │
    │                                  │         ├─────────────────────────►│
    │                                  │         │                         │ ProfitCalculator
    │                                  │         │                         │ KeyEligibility
    │                                  │         │      resultado          │
    │                                  │         │◄─────────────────────────│
    │                                  │         │                         │
    │                                  │  Service persiste resultado      │
    │                                  │◄────────┘                         │
    │  response JSON/Inertia           │                                   │
    │◄─────────────────────────────────┤                                   │
```

### Value Objects — quando usar

Value Objects agrupam dados relacionados e se validam no construtor. Usar quando:
- Uma função receberia 3+ parâmetros do mesmo conceito
- Os dados vêm de uma fonte externa e precisam de validação (ex: taxas do banco → `MarketplaceFee`)

NÃO usar quando:
- São 1-2 parâmetros simples (float, string) — primitivos bastam
- O dado já é representado por um Enum (Marketplace, KeyPlatform)

---

## Roadmap de refatoração

### Fase 6 — Segurança e infraestrutura ✅

### Fase 8 — CI/CD com GitHub Actions
> Objetivo: pipeline automatizado que valida qualidade a cada push.

- [ ] **8.1** Criar `.github/workflows/ci.yml`:
  - PHP 8.3 setup + `composer install`
  - Laravel Pint (`./vendor/bin/pint --test`) — formatação
  - PHPStan level 6 com `larastan` — análise estática de tipos
  - Pest (`php artisan test`) — suite completa
  - Coverage report (upload para Codecov)
- [ ] **8.2** Instalar PHPStan + `larastan` (`composer require --dev nunomaduro/larastan`)
- [ ] **8.3** Adicionar `phpstan.neon` configurado para `app/Domain/` nível 8, resto nível 5
- [ ] **8.4** Adicionar badge de CI no README
- [ ] **8.5** Migrar queue driver de `database` para `redis` (já disponível via Docker)

### Fase Futura 1 — API REST + Documentação (ignorar por enquanto)
> Objetivo: expor o sistema via API versionada e documentada — demonstra domínio de APIs para portfólio.

- [ ] **7.1** Instalar Laravel Sanctum para autenticação via tokens
- [ ] **7.2** Criar rotas em `routes/api.php` com prefixo `/api/v1/`
  - Keys: `GET /keys`, `POST /keys`, `PUT /keys/{id}`, `DELETE /keys/{id}`
  - Keys (operações): `POST /keys/auto-sell`, `POST /keys/import`, `PATCH /keys/sold`
  - Games: `GET /games`, `POST /games`, `GET /games/{id}`
  - Bundles: `GET /bundles`, `POST /bundles/sync`
  - VIPs: `GET /vips`, `POST /vips/{id}/execute`
- [ ] **7.3** Criar API Resources (transformers) para cada entidade — separa representação interna da API pública
- [ ] **7.4** Criar API Controllers que reutilizam os mesmos UseCases/Services (não duplicar lógica)
- [ ] **7.5** Instalar e configurar Scramble (ou L5-Swagger) para documentação OpenAPI automática
- [ ] **7.6** Padronizar respostas de erro da API (RFC 7807 Problem Details ou formato consistente)
- [ ] **7.7** Rodar testes de API (Feature Tests com `actingAs` + Sanctum)

### Fase Futura 2 — Normalizar FK entre keys e games
> Objetivo: substituir o vínculo por string `gamivo_id` (ID externo do marketplace) por uma FK integer adequada (`game_id`) entre `venda_chave_trocas` e `games`.
> **Pré-requisito**: `RegisterKeyUseCase` já garante criação do `Game` correspondente.

#### Contexto do problema

Hoje `venda_chave_trocas` se liga a `games` indiretamente, via string:

```
venda_chave_trocas.gamivo_id (varchar) ←→ games.id_gamivo (varchar)
```

Isso é um acoplamento ao ID externo do Gamivo, não uma FK real. Consequências:

1. **Sem integridade referencial** — uma key pode ter `gamivo_id` apontando para um game que não existe na tabela `games`
2. **JOINs em varchar indexado** — mais lentos que em integer (FK)
3. **Dados duplicados** — `game_name` e `region` vivem em `venda_chave_trocas` E em `games`, podendo divergir
4. **Frágil a mudanças externas** — se o Gamivo mudar o formato do ID, o link quebra silenciosamente
5. **Relationship `game()` depende da string** — `belongsTo(Game, 'gamivo_id', 'id_gamivo')` funciona mas não é o padrão Laravel

#### Estratégia (Expand-Contract)

- [ ] **F2.1** Migration **EXPAND** — adicionar `game_id` (bigint nullable) em `venda_chave_trocas` com FK para `games.id`
- [ ] **F2.2** Migration **MIGRATE** — backfill: para cada key, localizar `games.id` via `gamivo_id → id_gamivo` e popular `game_id`
- [ ] **F2.3** Ajustar `RegisterKeyUseCase` para persistir `game_id` após criar/localizar o game
- [ ] **F2.4** Migration: tornar `game_id` NOT NULL após validação em produção
- [ ] **F2.5** Reescrever relationship `game()` em `Venda_chave_troca` para FK padrão: `belongsTo(Game::class)`
- [ ] **F2.6** Reescrever `scopeWithoutRecentBundle` — `whereDoesntHave` com FK integer (mais rápido)
- [ ] **F2.7** Avaliar remoção de `game_name` e `region` de `venda_chave_trocas` — se sempre iguais ao `Game`, são dados denormalizados desnecessários
- [ ] **F2.8** Migration **CONTRACT** — remover coluna `gamivo_id` de `venda_chave_trocas`

#### Pontos de atenção

- **Keys órfãs**: antes de aplicar NOT NULL (F2.4), auditar keys cujo `gamivo_id` não corresponde a nenhum `Game`
- **`region` em duas tabelas**: verificar divergências antes de remover (F2.7)
- **Quebra do contrato externo**: API Resources devem continuar expondo via `$key->game->id_gamivo`
- **Tempo entre EXPAND e CONTRACT**: manter as duas colunas conviventes por pelo menos um ciclo de validação em produção

---

## Regras de negócio documentadas

- **Regra dos 21 dias**: keys de jogos lançados nos últimos 21 dias são excluídas do `autoSell()` para evitar venda prematura.
- **Tiers Gamivo**: abaixo de €8 e acima de €8 têm estruturas de fee diferentes.
- **Min/Max API Gamivo**: preço mínimo = 1.4×–1.6× do pago; máximo = 8×–30× (quanto mais barato o jogo, maior o múltiplo máximo).
- **Classificação de marketplace**: ~~G2A e Kinguin tinham categorias (VIP, Diamond, Gold...) baseadas em preço e nota Metacritic.~~ **REMOVIDO** — funcionalidade descontinuada.
- **Contagem de reclamações de fornecedor**: ~~Máquina de estado que rastreava reclamações por fornecedor.~~ **REMOVIDO** — funcionalidade descontinuada.
- **Importação XLSX**: cabeçalho obrigatório com 10 colunas (A=Data, B=Gamivo, C=URL perfil, D=Qtd. TF2, E=Bundle, F=Data expiração, G=Popularidade, H=Region Lock, I=Chave, J=Nome do Jogo); datas em formato serial do Excel são convertidas; `tf2_quantity` vazia (0) é rejeitada com erro.

---

## Variáveis de ambiente relevantes

```env
# Serviços externos
API_PRICE_RESEARCHER=
DEV_API_PRICE_RESEARCHER=
CARCA_API_GAMIVO=
SISTEMA_ESTOQUE_BASE_URL=
DEV_SISTEMA_ESTOQUE_BASE_URL=

# Auth Google
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=

# Admin
ADMIN_EMAIL=carcadeals@gmail.com
EXTERNAL_SECRET=
```
