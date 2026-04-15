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

Atue sempre como arquiteto de software sênior com conhecimento profundo de Laravel e clean architecture.
- Questione decisões quando houver práticas consolidadas no mercado que apontem em outra direção
- Proponha soluções que o Laravel oferece, sempre respeitando as camadas de arquitetura a ser seguida
- Explique o raciocínio antes de implementar — nunca apenas execute sem contextualizar
- Quando o Laravel oferecer algo relevante, apresente o que ele resolve, onde vive nas camadas e qual o custo de usá-lo
- Nunca coloque lógica de negócio fora do Domain
- Ao sugerir onde um novo arquivo deve viver, justifique com base na camada correta da arquitetura
- Nomes de variáveis dentro do código sempre em inglês, utilize português somente em comentários
- Não comente nada sobre decisões futuras
- Mantenha sempre boas práticas (Cesign Patterns, Clean Code, SOLID, etc)
- Identifique possíveis Code Smells, alerte e proponha soluções.

---

## Arquitetura atual

```
Laravel (Inertia.js + Vue)
│
├── app/
│   ├── Models/           # 16 modelos Eloquent
│   ├── Http/
│   │   ├── Controllers/  # 9 controllers (lógica misturada com orquestração)
│   │   ├── Requests/     # 13 Form Requests (validação de entrada)
│   │   └── Helpers/
│   │       └── Formulas.php  # Cálculos por marketplace
│   ├── Services/         # 9 services (lógica de negócio parcialmente extraída)
│   └── Observers/
│       └── GameObserver.php  # Auto-preenche id_gamivo
│
├── database/
│   └── migrations/       # 32 migrações
│
└── routes/
    └── web.php           # Todas as rotas (sem API separada)
```


---

## Domínios do sistema

### 1. Keys (`Venda_chave_troca`)
Modelo central. Representa keys compradas e/ou vendidas.

Campos relevantes:
- PARA REMOVER -> id_fornecedor, notaMetacritic, isSteam, randomClassificationG2A, randomClassificationKinguin, id_leilao_g2a, id_leilao_gamivo, id_leilao_kinguin, id_plataforma, precoVenda, incomeReal, chaveEntregue, vendido(como tem data de venda, não é necessário), leiloes, quantidade, devolucoes, 
- `tipo_reclamacao_id` - id do problema que deu na key, é importante para saber qual problema deu e agrupar
- `notaMetacritic` - id do problema que deu na key, é importante para saber qual problema deu e agrupar
- `steamId` - id na steam, plataforma que vende os jogos oficiais
- `nomeJogo`, `region`, `plataforma_id` - nome, região que ele está limitado(EU = Europa por exemplo), plataforma que vai ser vendido
- `valorPagoIndividual` — custo individual da key
- `qtdTF2` — custo da trade na qual aquela key pertence
- `precoCliente` — preço no marketplace na data de compra
- `incomeSimulado` — receita líquida após taxas
- `lucroRS`, `lucroPercentual` — lucro na compra (valor absoluto e valor percentual)
- `valorVendido`, `lucroVendaRS`, `lucroVendaPercentual` — valor absoluto na venda e lucro (valor absoluto e valor percentual)
- `idGamivo`, `idSteamcharts` — IDs externos para automação
- `chaveRecebida` — código da key para enviar ao cliente
- `dataAdquirida` — data que adquiriu a key
- `dataVenda`(data posto a venda) — data que botou o jogo para vender
- `dataVendida` — data que vendeu a key
- `dataExpiracao` — data que a key se torna inválida(deve vender antes)
- `perfilOrigem` — url do fornecedor que vendeu a key
- `minApiGamivo`, `maxApiGamivo` — valores mínimos e máximos que a API Gamivo pode chegar(já descontando a taxa)

Fluxo principal:
1. Key é inserida manualmente ou via importação XLSX
2. `CalculateService` calcula fórmulas de lucro e preço
3. `autoSell()` sugere keys prontas para listagem/vender (exclui jogos em budles recentes, < 21 dias)
4. `updateSoldOffers()` atualiza com dados de venda (valorVendido, lucroVendaRS, lucroVendaPercentual, dataVendida)

### 2. Cálculo de lucro (`CalculateService` + `Formulas`)
Cada marketplace tem estrutura de taxa diferente:

| Marketplace | Fórmula simplificada |
|-------------|----------------------|
| G2A         | REMOVER |
| Gamivo      | `precoCliente × (1 - %fee) - fee_fixo` (2 tiers: < €8 e ≥ €8) |
| Kinguin     | REMOVER |
| Troca       | REMOVER |

**Atenção:** `Formulas` faz 4 queries no banco (tabela `taxas`) a cada instância — ou seja, a cada request que envolve cálculo.

### 3. Bundles
Agrupamento de jogos (tipo `bundle` ou `choice`). Relacionamento many-to-many com `Game` via `bundle_games`. A tabela pivot armazena `bundle_launch_price`.

A regra dos 21 dias usa a `bundle_games.created_at` para excluir lançamentos recentes do `autoSell()`.

### 4. VIPs e automação
- `Vip` representa um cliente VIP com `id_steam`
- `VipList` representa uma execução de lista para aquele VIP (status: `queued` | `completed` | `failed`)
- Fluxo: controller chama `VipListExecutionService::queueRunForVip()` → HTTP POST para `price_researcher` → `price_researcher` chama webhook de callback → `applyCallback()` persiste resultado

### 5. Autorização
- Google OAuth via Socialite REMOVER
- `AuthorizedUsers` — tabela que controla quem pode acessar (`can-edit`)
- Admin hardcoded: `Gate::define('is-admin', fn($u) => $u->email === 'carcadeals@gmail.com')`

---

## Problemas identificados

### Críticos (risco em produção)

**1. Admin hardcoded no código**
```php
// AppServiceProvider.php
return $user->email === 'carcadeals@gmail.com';
```
Se o email mudar, o acesso admin é perdido. Não há outro admin. Sem como gerenciar pelo sistema.

**2. Rotas públicas sem autenticação**
- `/games/updatePopularity` — qualquer um pode alterar popularidade de jogos
- `/games/paginated`, `/games/search` — dados expostos sem auth
- Callback VIP (`/vips/callback/{id}`) sem verificação de origem — qualquer POST externo pode injetar resultados

**3. `Formulas` faz queries no banco no construtor**
```php
// Formulas.php __construct()
$this->taxa_gamivo_1 = Taxas::where(...)->first();
$this->taxa_gamivo_2 = Taxas::where(...)->first();
// ... 4x por instância
```
Toda request que envolve cálculo abre 4 queries adicionais. Sem cache.

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

**5. `VendaChaveTrocaController` faz muita coisa**
Tem 14+ métodos cobrindo CRUD, importação, cálculo automático, consulta de venda, sugestão de listagem. Deveria ser dividido.

**6. Validação fraca em campos críticos**
- Preços (`valorPagoIndividual`, `precoCliente`) não têm validação de mínimo > 0 nos Form Requests
- `notaMetacritic` aceita qualquer inteiro (0 a 100 mas sem rule `between`)
- Nenhuma validação de unicidade de key antes de inserir

**7. `autoSell()` com query complexa sem teste**
Query com 4 JOINs + subquery `WHERE EXISTS`. Mudanças aqui são arriscadas sem testes automatizados.

**8. `GameController::store()` busca o game duas vezes**
```php
$created = Game::create($data);
// ...
return Game::select('*')->where('id', $created->id)->with(['bundles'])->first();
```
Poderia usar `$created->load('bundles')`.

**9. Nenhuma verificação da origem do webhook VIP**
`/vips/callback/{vipListId}` aceita qualquer POST sem token ou assinatura. Se o ID for descoberto, resultados podem ser adulterados.

**10. Importação XLSX loga a key parcialmente**
```php
// FileService.php ~linha 188
Log::info('Chave: ' . substr($key, 0, 10));
```
Mesmo parcial, keys em log de produção são risco de segurança.

**11. Nomes de modelo e rota inconsistentes**
- Modelo: `Venda_chave_troca` (snake_case com maiúscula)
- Tabela: `venda_chave_trocas`
- Rota: `/venda-chave-troca`
- Relações: `tipoReclamacao`, `leilaoG2A`, `leilaoKinguin` — sem padrão

**12. `tipo_reclamacao_id` com validação `min:1 max:4` hardcoded**
Se novos tipos forem cadastrados, a validação quebra sem alterar o código.

### Menores (débito técnico)

**13. Sem testes automatizados**
Nenhum test file encontrado. Cálculos de lucro, importação e queries complexas não têm cobertura.

**14. Queue driver: database**
Jobs usam a tabela do banco como fila. Sob carga, pode gerar lock contention. Redis seria mais robusto.

**15. Campos depreciados no modelo Vip**
`first_link`, `second_link`, `third_link`, `steam_link` — marcados como deprecated na migration mas ainda no modelo. Remover esses campos.

**16. Sem paginação em `searchPopularity()`**
Carrega todos os jogos com `id_steamcharts` não nulo de uma vez.

---

## Arquitetura alvo: Laravel Modular + Domain Layer leve

### Contexto de decisão

Este sistema é uma ferramenta **operacional interna** com:
- Dois usuário apenas (operadores das keys)
- Volume de dados moderado (keys, bundles, VIPs)
- Cálculos financeiros críticos que não podem errar
- Integrações com serviços externos (price_researcher, Gamivo)
- Sem necessidade de escala horizontal

**Clean Architecture completa não é indicada.** Repositories abstratos e Adapters adicionariam ~20-30 arquivos de boilerplate sem benefício real. Nunca vamos trocar o Laravel, e o sistema tem ~10.8k LOC (5k PHP backend + 4.7k Vue frontend + 1k migrations).

**O que adotamos:** Clean Architecture podada — mantém o que importa (domínio isolado e testável, use cases para workflows complexos), descarta o que não interessa(interfaces de repository, adapters). A camada `Domain/` é PHP puro (sem Eloquent, sem framework). Use Cases orquestram workflows multi-step. Services lidam com infraestrutura (banco, APIs, cache). Controllers só recebem HTTP.

### Princípio central

> **Controllers** recebem HTTP e delegam para **UseCases** (workflows complexos) ou **Services** (operações simples).
> **UseCases** orquestram o fluxo — chamam Services para infra e Domain para regras. Um UseCase = uma operação de negócio completa.
> **Services** acessam infraestrutura (Eloquent, APIs externas, cache). Não contêm regras de negócio.
> **Domain** é PHP puro — recebe valores primitivos e Value Objects, retorna resultados. Zero dependência do Laravel.

### Quando usar UseCase vs Service direto

| Situação | Caminho | Exemplo |
|----------|---------|---------|
| Workflow multi-step que cruza domínios | Controller → UseCase → Services + Domain | Registrar key (10+ passos) |
| Operação simples / CRUD | Controller → Service | Deletar key, buscar por ID |
| Regra de negócio pura | Qualquer camada → Domain | Calcular lucro, verificar elegibilidade |

### Estrutura alvo

```
app/
├── Domain/                                    # PHP PURO — zero dependência do Laravel
│   ├── Pricing/                               # Cálculos financeiros
│   │   ├── ProfitCalculator.php               # Lucro real, percentual, venda
│   │   ├── IncomeCalculator.php               # Income simulado e real por marketplace
│   │   ├── SalePriceCalculator.php            # Preço de venda (calcPrecoVenda)
│   │   ├── MinMaxPriceCalculator.php          # Min/max API Gamivo
│   │   └── ValueObjects/
│   │       ├── MarketplaceFee.php             # VO: taxas por marketplace (gamivo tiers, G2A, Kinguin)
│   │       ├── KeyPricing.php                 # VO: precoCliente, precoVenda, valorPagoIndividual
│   │       └── G2ATaxRange.php                # VO: faixa de preço + taxa do ranges_taxa_g2a
│   │
│   ├── Keys/                                  # Regras do ciclo de vida das keys
│   │   ├── KeyEligibility.php                 # Regra dos 21 dias, elegibilidade para venda
│   │   ├── KeyPriceAging.php                  # Degradação de preço por idade (limbo, 12/9/6/3 meses)
│   │   └── DuplicateKeyChecker.php            # Validação de unicidade de chave
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
│       ├── Marketplace.php                    # G2A(2), Gamivo(3), Kinguin(4), Troca(7)
│       └── KeyPlatform.php                    # Steam, EA, EGS, GOG, Xbox, PSN, Desconhecido
│
├── UseCases/                                  # ORQUESTRAÇÃO de workflows complexos
│   ├── Keys/
│   │   ├── RegisterKeyUseCase.php             # store(): cálculos + fornecedor + plataforma + persistência
│   │   ├── UpdateKeyUseCase.php               # update(): recalcula + atualiza fornecedor
│   │   ├── ImportKeysFromXlsxUseCase.php      # Validação XLSX + registro em batch
│   │   ├── AutoSellUseCase.php                # Busca keys elegíveis + regras de elegibilidade
│   │   └── UpdateSoldOffersUseCase.php        # Atualiza keys vendidas + cálculo de lucro de venda
│   ├── Bundles/
│   │   └── SyncBundlesFromApiUseCase.php      # Fetch API + criar bundles + preços + associar jogos
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
│   │   └── BundleService.php                  # CRUD bundles, associação de jogos, preços
│   ├── Suppliers/
│   │   └── SupplierService.php                # CRUD fornecedor
│   ├── Vips/
│   │   └── VipListExecutionService.php        # Já bem estruturado — manter
│   └── External/
│       └── CurrencyConversionService.php      # API AwesomeAPI (atual APIService)
│
├── Http/
│   ├── Controllers/                           # SÓ HTTP — request → use case/service → response
│   │   ├── Keys/
│   │   │   ├── KeyController.php              # CRUD (store → RegisterKeyUseCase, destroy direto)
│   │   │   ├── KeyImportController.php        # import → ImportKeysFromXlsxUseCase
│   │   │   └── KeySaleController.php          # autoSell → AutoSellUseCase, etc.
│   │   ├── Games/
│   │   │   └── GameController.php
│   │   ├── Bundles/
│   │   │   └── BundleController.php
│   │   └── Vips/
│   │       └── VipController.php
│   └── Requests/                              # Manter e fortalecer validações
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
    │                                  │  KeyPricing(preco, venda, pago)   │
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
- Uma função receberia 3+ parâmetros do mesmo conceito (ex: precoCliente, precoVenda, valorPagoIndividual → `KeyPricing`)
- Os dados vêm de uma fonte externa e precisam de validação (ex: taxas do banco → `MarketplaceFee`)

NÃO usar quando:
- São 1-2 parâmetros simples (float, string) — primitivos bastam
- O dado já é representado por um Enum (Marketplace, KeyPlatform)

| Value Object | O que agrupa | Validação no construtor |
|-------------|-------------|------------------------|
| `MarketplaceFee` | Taxas percentuais e fixas por tier (Gamivo), G2A, Kinguin | Taxas ≥ 0, percentuais entre 0 e 1 |
| `KeyPricing` | precoCliente, precoVenda, valorPagoIndividual | Valores > 0 |
| `G2ATaxRange` | Faixa min/max de preço + taxa associada | Min < max, taxa ≥ 0 |

### Mapa de migração: de onde → para onde

Este mapa mostra onde cada pedaço de lógica está hoje e para onde vai.

#### Pricing (Cálculos financeiros)

| Lógica | Onde está HOJE | Para onde vai (o que é) |
|--------|---------------|---------------|
| `calcPrecoVenda()` | `Formulas.php:22-47` | `Domain/Pricing/SalePriceCalculator` |
| `calcIncomeSimulado()` | `Formulas.php:79-105` | `Domain/Pricing/IncomeCalculator` |
| `calcIncomeReal()` | `Formulas.php:49-77` | REMOVER |
| `calcValorPagoIndividual()` | `Formulas.php:107-122` | `Domain/Pricing/ProfitCalculator` (valor que pago individualmente nesse jogo) |
| `calcLucroReal()` | `Formulas.php:124-131` | `Domain/Pricing/ProfitCalculator` (lucro absoluto esperado ao comprar) |
| `calcLucroPercentual()` | `Formulas.php:133-141` | `Domain/Pricing/ProfitCalculator` (lucro percentual esperado ao comprar) |
| `calcLucroVendaReal()` | `Formulas.php:143-150` | `Domain/Pricing/ProfitCalculator` (lucro absoluto ao vender) |
| `calcLucroVendaPercentual()` | `Formulas.php:152-159` | `Domain/Pricing/ProfitCalculator` (lucro percentual ao vender) |
| `calculateMinMaxApi()` | `CalculateService.php:27-61` | `Domain/Pricing/MinMaxPriceCalculator` (valores mínimos e máximos que a API Gamivo pode chegar) |
| Queries de taxas no construtor | `Formulas.php:__construct` | `Services/Keys/KeyCalculationService` (com cache) → passa `MarketplaceFee` VO para Domain |
| `calculateFormulas()` (DUPLICADA) | `VendaChaveTrocaController:580-601` + `CalculateService:66-107` | Versão única em `Services/Keys/KeyCalculationService` |
| Orquestração do `store()` (10+ passos) | `VendaChaveTrocaController::store():191-268` | `UseCases/Keys/RegisterKeyUseCase` |
| Orquestração do `update()` | `VendaChaveTrocaController::update():273-330` | `UseCases/Keys/UpdateKeyUseCase` |
| Orquestração do `autoSell()` | `VendaChaveTrocaController::autoSell():369-417` | `UseCases/Keys/AutoSellUseCase` (buscar jogos elegíveis para serem vendidos) |
| Orquestração do `updateSoldOffers()` | `VendaChaveTrocaController::updateSoldOffers():428-461` | `UseCases/Keys/UpdateSoldOffersUseCase` (recebe POST da API Gamivo com os dados de venda dos jogos) |
| Orquestração importação XLSX | `FileService::validateAndProcess()/storeKeys()` | `UseCases/Keys/ImportKeysFromXlsxUseCase` (importação de jogos novos) |
| Sync bundles da API | `BundleService::createBundlesFromAPI():50-92` | `UseCases/Bundles/SyncBundlesFromApiUseCase` (busca dados de bundles na API GG deals e armazena) |
| Execução lista VIP | `VipListExecutionService::queueRunForVip()` | `UseCases/Vips/ExecuteVipListUseCase` (busca lista de jogos de fornecedores e manda para o PRICE buscar o preço) |

#### Classification (REMOVIDO)

As classificações G2A (`classificacaoRandomG2A()` em `Formulas.php:161-182`) e Kinguin (`classificacaoRandomKinguin()` em `Formulas.php:184-197`) serão **removidas do sistema**, não migradas. Deletar o código na refatoração.

#### Keys (Regras de ciclo de vida)

| Lógica | Onde está HOJE | Para onde vai |
|--------|---------------|---------------|
| Regra dos 21 dias (bundle recente) | `VendaChaveTrocaController::autoSell():384` (inline na query) | `Domain/Keys/KeyEligibility::isEligibleForSale()` |
| Regra "não pode ser gift link" | `VendaChaveTrocaController::autoSell()` (inline na query) | `Domain/Keys/KeyEligibility::isEligibleForSale()` |
| Regra "deve ter idGamivo" | `VendaChaveTrocaController::autoSell()` (inline na query) | `Domain/Keys/KeyEligibility::isEligibleForSale()` |
| Degradação de preço por idade | `GameService::updateMinPrices():111-130` | `Domain/Keys/KeyPriceAging::calculateAgedPrice()` |
| Detecção de keys em limbo | `KeyService::checkLimboKeys():20-55` | `Domain/Keys/KeyPriceAging::calculateLimboPrice()` |
| Verificação de key duplicada | `VendaChaveTrocaController::store()` (inline) | `Domain/Keys/DuplicateKeyChecker` |

#### Suppliers (Fornecedores)

| Lógica | Onde está HOJE | Para onde vai |
|--------|---------------|---------------|
| Máquina de estado de reclamações | `VendaChaveTrocaController::editarFornecedor():543-578` | **REMOVIDO** — contagem de reclamações será eliminada do sistema |
| Criação/atualização de fornecedor | `GameService::criarAdicionarFornecedor():159-181` | `Services/Suppliers/SupplierService` (CRUD simples, sem lógica de reclamações) |

#### Platform (Identificação)

| Lógica | Onde está HOJE | Para onde vai |
|--------|---------------|---------------|
| Regex de plataforma por formato de key | `GameService::identifyPlatform():135-154` | `Domain/Platform/PlatformIdentifier` |

#### Import (Importação XLSX)

| Lógica | Onde está HOJE | Para onde vai |
|--------|---------------|---------------|
| Conversão de datas do Excel | `FileService::convertExcelDate():363-406` | `Domain/Import/ExcelDateConverter` |
| Validação de cabeçalhos | `FileService::validateHeaders():86-103` | `Domain/Import/ImportHeaderValidator` |
| Validação de linhas | `FileService::validateRow():330-346` | `Domain/Import/ImportRowValidator` |
| Orquestração da importação | `FileService::validateAndProcess()/storeKeys()` | `Services/Keys/KeyImportService` |

#### Bundles

| Lógica | Onde está HOJE | Para onde vai |
|--------|---------------|---------------|
| Determinar tipo (choice vs bundle) | `BundleService::createBundlesFromAPI():50-92` (inline) | `Domain/Bundles/BundleTypeResolver` |

#### Enums (IDs hardcoded → tipagem forte)

| Valor hardcoded | Onde aparece | Substituído por |
|-----------------|-------------|-----------------|
| `id_plataforma = 2` (G2A) | `Formulas.php`, `CalculateService`, controllers | `Marketplace::G2A` REMOVER |
| `id_plataforma = 3` (Gamivo) | `Formulas.php`, `FileService`, controllers | `Marketplace::Gamivo` |
| `id_plataforma = 4` (Kinguin) | `Formulas.php`, controllers | `Marketplace::Kinguin` REMOVER |
| `tipo_formato_id = 7` (Troca) | `Formulas.php` | `Marketplace::Troca` REMOVER |
| Regexes de plataforma | `GameService.php` | `KeyPlatform` enum com método `fromKeyFormat()` |

---

## Roadmap de refatoração

### Estratégia de migração de dados

**Regra: nunca migrar código e dados ao mesmo tempo.** As Fases 0-5 refatoram o código SEM tocar no banco. O Eloquent desacopla o nome do model do nome da tabela/coluna via `$table` e `Attribute` accessors.

Quando o código estiver refatorado e testado, migrar o schema usando **Expand-Contract Pattern**:
1. **EXPAND** — migration adiciona coluna/tabela nova (antiga permanece)
2. **MIGRATE** — migration copia dados da antiga para nova
3. **SWITCH** — código passa a usar a nova
4. **CONTRACT** — migration remove a antiga (só após validação em produção)

Cada step é uma migration separada e reversível. Se der errado em qualquer ponto, a coluna/tabela antiga ainda existe com dados intactos.

### Fase 0 — Fundação (antes de qualquer refatoração)
> Objetivo: criar a rede de segurança para refatorar sem medo.

- [ ] **0.1** Criar Enums `Marketplace` e `KeyPlatform` em `app/Domain/Enums/`
- [ ] **0.2** Escrever testes para o comportamento ATUAL do `Formulas.php` e `CalculateService` (testes de caracterização)
  - Testar marketplace: Gamivo (<8 e ≥8) os outros serão deletados
  - Testar `calculateMinMaxApi` com diferentes faixas de preço
  - Esses testes vão garantir que a refatoração não muda o resultado
- [ ] **0.3** Escrever testes para `autoSell()` — especialmente a regra dos 21 dias

### Fase 1 — Extrair Domain/Pricing (maior risco financeiro)
> Objetivo: centralizar TODOS os cálculos financeiros em PHP puro.

- [ ] **1.1** Criar Value Objects em `Domain/Pricing/ValueObjects/`:
  - `MarketplaceFee` — encapsula taxas vindas do banco (Gamivo tiers, G2A, Kinguin)
  - `KeyPricing` — agrupa precoCliente, precoVenda, valorPagoIndividual
  - `G2ATaxRange` — faixa de preço + taxa associada
- [ ] **1.2** Criar `Domain/Pricing/IncomeCalculator` — extrair `calcIncomeSimulado()` e `calcIncomeReal()` do `Formulas.php`
- [ ] **1.3** Criar `Domain/Pricing/SalePriceCalculator` — extrair `calcPrecoVenda()` REMOVER
- [ ] **1.4** Criar `Domain/Pricing/ProfitCalculator` — extrair `calcValorPagoIndividual()`, `calcLucroReal()`, `calcLucroPercentual()`, `calcLucroVendaReal()`, `calcLucroVendaPercentual()`
- [ ] **1.5** Criar `Domain/Pricing/MinMaxPriceCalculator` — extrair `calculateMinMaxApi()`
- [ ] **1.6** Refatorar `KeyCalculationService` — buscar taxas com cache, converter para Value Objects, delegar para classes Domain
- [ ] **1.7** Eliminar `calculateFormulas()` duplicado do controller
- [ ] **1.8** Deletar `Formulas.php` — toda lógica já migrada
- [ ] **1.9** Rodar testes da Fase 0 — todos devem passar com resultados idênticos

### Fase 2 — Extrair Domain/Keys e Domain/Platform
> Objetivo: centralizar regras de elegibilidade e identificação de plataforma.

- [ ] **2.1** Criar `Domain/Keys/KeyEligibility` — regra dos 21 dias, gift link, idGamivo obrigatório
- [ ] **2.2** Criar `Domain/Keys/KeyPriceAging` — degradação de preço por idade + limbo
- [ ] **2.3** Criar `Domain/Keys/DuplicateKeyChecker`
- [ ] **2.4** Criar `Domain/Platform/PlatformIdentifier` — extrair `identifyPlatform()` do `GameService`
- [ ] **2.5** Rodar testes

### Fase 3 — Remover código morto (Classification e Supplier Complaints)
> Objetivo: eliminar funcionalidades descontinuadas antes de reorganizar.

- [ ] **3.1** Remover `classificacaoRandomG2A()` e `classificacaoRandomKinguin()` do `Formulas.php` e qualquer referência nos controllers/views
- [ ] **3.2** Remover lógica de contagem de reclamações de `editarFornecedor()` e `criarAdicionarFornecedor()`
- [ ] **3.3** Remover campos/colunas de reclamação se não forem usados por outras funcionalidades
- [ ] **3.4** Rodar testes

### Fase 4 — Extrair Domain/Import e Domain/Bundles
> Objetivo: isolar regras de importação e bundles.

- [ ] **4.1** Criar `Domain/Import/ExcelDateConverter`, `ImportHeaderValidator`, `ImportRowValidator`
- [ ] **4.2** Criar `Domain/Bundles/BundleTypeResolver`
- [ ] **4.3** Rodar testes

### Fase 5 — Criar UseCases e reorganizar Services
> Objetivo: separar orquestração (UseCases) de infraestrutura (Services). Controllers magros.

- [ ] **5.1** Criar `UseCases/Keys/RegisterKeyUseCase` — extrair `store()` do controller
  - Orquestra: KeyCalculationService + SupplierService + GameService + Domain (DuplicateKeyChecker, PlatformIdentifier, MinMaxPriceCalculator)
- [ ] **5.2** Criar `UseCases/Keys/UpdateKeyUseCase` — extrair `update()` do controller
  - Orquestra: KeyCalculationService + SupplierService + GameService
- [ ] **5.3** Criar `UseCases/Keys/AutoSellUseCase` — extrair `autoSell()` do controller
  - Orquestra: KeyRepository (query) + Domain/Keys/KeyEligibility (regras)
- [ ] **5.4** Criar `UseCases/Keys/UpdateSoldOffersUseCase` — extrair `updateSoldOffers()` do controller
  - Orquestra: KeyRepository (busca) + Domain/Pricing/ProfitCalculator (cálculos de venda)
- [ ] **5.5** Criar `UseCases/Keys/ImportKeysFromXlsxUseCase` — extrair de `FileService`
  - Orquestra: Domain/Import (validação) + RegisterKeyUseCase (registro por linha)
- [ ] **5.6** Criar `UseCases/Bundles/SyncBundlesFromApiUseCase` — extrair de `BundleService`
  - Orquestra: BundleService (API/DB) + Domain/Bundles/BundleTypeResolver + CurrencyConversionService
- [ ] **5.7** Criar `UseCases/Vips/ExecuteVipListUseCase` — extrair de `VipListExecutionService`
- [ ] **5.8** Refatorar Services para serem infraestrutura pura:
  - `KeyCalculationService` → carrega taxas com cache, converte para VOs
  - `KeyRepository` → queries complexas (autoSell, limbo, sold)
  - `SupplierService` → CRUD fornecedor
  - `GameService` → lookup Gamivo, Steam ID, CRUD
  - `BundleService` → CRUD bundles, API GGDeals
  - `CurrencyConversionService` → mover de `APIService`
- [ ] **5.9** Dividir `VendaChaveTrocaController` em `KeyController`, `KeyImportController`, `KeySaleController`
  - Cada método do controller: valida request → chama UseCase ou Service → retorna response
- [ ] **5.10** Atualizar `routes/web.php` para apontar para novos controllers
- [ ] **5.11** Deletar `FileService.php`, `CalculateService.php`, `Formulas.php` — toda lógica já migrada
- [ ] **5.12** Rodar testes completos

### Fase 6 — Segurança e infraestrutura
> Objetivo: corrigir vulnerabilidades identificadas.

- [ ] **6.1** Mover admin para env ou campo `is_admin` na tabela
- [ ] **6.2** Proteger webhook VIP com token secreto
- [ ] **6.3** Adicionar auth às rotas públicas (`updatePopularity`, `paginated`, `search`)
- [ ] **6.4** Remover log parcial de keys (`FileService ~linha 188`)
- [ ] **6.5** Fortalecer validações de preço nos Form Requests
- [ ] **6.6** Substituir `min:1 max:4` por `exists:tipo_reclamacoes,id`

### Fase 7 — CI/CD com GitHub Actions
> Objetivo: pipeline automatizado que garante qualidade a cada push.

- [ ] **8.1** Criar `.github/workflows/ci.yml` com:
  - PHP setup (matrix: 8.2, 8.3)
  - `composer install`
  - PHPStan level 6+ (`  analyse app/`)
  - Laravel Pint (`./vendor/bin/pint --test`)
  - PHPUnit (`php artisan test`)
  - Coverage report (opcional: upload para Codecov)
- [ ] **8.2** Instalar e configurar PHPStan (com `larastan`) — análise estática de tipos
- [ ] **8.3** Instalar e configurar Laravel Pint — formatação automática de código
- [ ] **8.4** Adicionar badge de CI/CD no README
- [ ] **8.5** (Opcional) Pipeline de deploy automático para staging/produção

### Fase 8 — Docker Compose para desenvolvimento
> Objetivo: ambiente reproduzível com um comando — demonstra DevOps awareness.

- [ ] **9.1** Criar `Dockerfile` para a aplicação Laravel (PHP-FPM + extensões necessárias)
- [ ] **9.2** Criar `docker-compose.yml` com:
  - `app` (PHP-FPM)
  - `web` (Nginx)
  - `db` (MySQL/PostgreSQL)
  - `redis` (para cache e filas)
  - `node` (para build do frontend Vue)
- [ ] **9.3** Criar `Makefile` ou scripts em `/scripts` para comandos comuns:
  - `make up` / `make down` — subir/descer ambiente
  - `make test` — rodar testes
  - `make lint` — rodar PHPStan + Pint
  - `make fresh` — migrate:fresh + seed
- [ ] **9.4** Documentar setup no README: `git clone` → `make up` → sistema rodando
- [ ] **9.5** Migrar queue driver de `database` para `redis` (já disponível via Docker)

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

### Notas sobre o roadmap

- **Cada fase termina com "rodar testes"** — nunca avançar sem verde.
- **Fase 0 é obrigatória** — sem testes de caracterização, qualquer refatoração é arriscada.
- **Fases 1-4 são independentes entre si** — podem ser reordenadas, mas Fase 1 tem maior impacto financeiro.
- **Fase 5 depende das Fases 1-4** — os UseCases consomem as classes Domain criadas antes.
- **Fase 6 é independente** — pode ser feita em paralelo com qualquer fase.
- **Fases 7-9 são de infraestrutura/portfólio** — independentes entre si e das fases anteriores (exceto que testes devem existir antes do CI/CD).

---

## Regras de negócio documentadas

- **Regra dos 21 dias**: keys de jogos lançados nos últimos 21 dias são excluídas do `autoSell()` para evitar venda prematura.
- **Tiers Gamivo**: abaixo de €8 e acima de €8 têm estruturas de fee diferentes.
- **Min/Max API Gamivo**: preço mínimo = 1.4×–1.6× do pago; máximo = 8×–30× (quanto mais barato o jogo, maior o múltiplo máximo).
- **Classificação de marketplace**: ~~G2A e Kinguin tinham categorias (VIP, Diamond, Gold...) baseadas em preço e nota Metacritic.~~ **REMOVIDO** — funcionalidade descontinuada.
- **Contagem de reclamações de fornecedor**: ~~Máquina de estado que rastreava reclamações por fornecedor.~~ **REMOVIDO** — funcionalidade descontinuada.
- **Importação XLSX**: cabeçalho obrigatório com 11 colunas específicas; datas em formato serial do Excel são convertidas.

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

# Admin (sugerido — ainda não implementado)
ADMIN_EMAIL=carcadeals@gmail.com
VIP_WEBHOOK_SECRET=
```
