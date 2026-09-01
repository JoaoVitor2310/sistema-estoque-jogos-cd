# Arquitetura

**Clean Architecture podada** — domínio isolado e testável, sem boilerplate de repositories abstratos ou adapters. Sistema interno com dois usuários; nunca precisaremos trocar o framework. Ver [`docs/adr/0001`](../adr/0001-pruned-clean-architecture.md) para a decisão completa.

> Hoje o sistema opera **exclusivamente na Gamivo**. As diretrizes de estrutura para um eventual segundo marketplace foram centralizadas em [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md).

## Princípio central

| Camada | Responsabilidade |
|--------|-----------------|
| **Controller** | Recebe HTTP, delega para UseCase ou Service. Sem lógica. |
| **UseCase** | Orquestra workflows multi-step. Um UseCase = uma operação completa. |
| **Service** | Acessa infraestrutura (Eloquent, APIs, cache). Sem regras de negócio. |
| **Domain** | PHP puro. Sem Eloquent, sem framework. Recebe primitivos/VOs, retorna resultados. |

## Quando usar UseCase vs Service direto

**Critério:** um UseCase é uma operação de **escrita** disparada de fora (HTTP, cron, CLI) que **orquestra passos**. Orquestrar passos basta — não conte colaboradores, não exija que cruze domínios: montar URL, autenticar, chamar serviço externo e traduzir a falha já é orquestração, mesmo com um colaborador só. Ver [`docs/adr/0007`](../adr/0007-usecase-promotion-criteria.md) para as alternativas descartadas.

Ordenado do caso mais comum para o mais raro:

| Situação | Caminho | Exemplo |
|---|---|---|
| **Leitura**, com ou sem filtro | Controller → Repository/Service | `KeyRepository::paginate()` |
| **Escrita** que orquestra passos | Controller/Scheduler → UseCase → Services + Domain | `AlertExpiringKeysUseCase` |
| Statement único sobre **um** modelo (find/create/update/delete, sem branch, sem efeito secundário) | Controller → Eloquent | `FeeController::destroy` |
| Regra de negócio pura | Domain direto | `MinimumMarginPolicy` |

**Leitura nunca vira UseCase**, por mais filtro que tenha — vai para Repository/Service, com a whitelist de filtros declarada num FormRequest (ver [`security-and-guardrails.md`](security-and-guardrails.md)). Isso não é sobre tamanho: separar os dois lados desde já é o que torna barata a adoção de **CQRS**, direção pretendida para o sistema.

A assimetria é proposital: `FeeController` fala Eloquent direto enquanto `GameController` delega a um UseCase. O que separa os dois é a **natureza da operação** — statement único versus passos orquestrados — não o tamanho do arquivo. Não "uniformize" sem ler o ADR 0007.

Corolário que envelhece na prática: operação que hoje é statement único e amanhã ganha uma segunda etapa (um log, uma chamada externa, uma validação que consulta outra tabela) cruzou a linha e vira UseCase **no mesmo commit**.

## Wrappers privados — regra

Só crie um método privado se ele: (a) é chamado em 3+ lugares, (b) revela intenção que a implementação esconde, ou (c) encapsula variação independente. Caso contrário, inline.

```php
// ❌ Wrapper sem valor
private function identifyPlatform(string $keyCode): string {
    return PlatformIdentifier::identify($keyCode);
}

// ✅ Inline
$game['identified_platform'] = PlatformIdentifier::identify($game['key_code']);
```

## Value Objects — quando usar

Usar quando uma função receberia 3+ parâmetros do mesmo conceito ou os dados vêm de fonte externa e precisam de validação (ex: taxas do banco → `MarketplaceFee`). Não usar para 1-2 primitivos simples.

## DTOs — entrada dos UseCases

O input tipado de um UseCase mora em `app/UseCases/<Domínio>/DTO/`, com sufixo `DTO` no nome da classe (ex: `App\UseCases\Financial\DTO\RecordTransferDTO`). O FormRequest correspondente o monta num método `toDTO()` — o mapeamento payload → tipos fica na fronteira HTTP, não no controller.

**DTO não é Value Object.** A tabela abaixo separa os dois; a distinção importa porque só uma das duas famílias pode conter regra de negócio:

| | DTO | Value Object |
|---|---|---|
| Onde | `app/UseCases/<Domínio>/DTO/` | `app/Domain/<Domínio>/` (ou `ValueObjects/`) |
| Para quê | carregar primitivos já validados até o UseCase | representar um conceito do domínio |
| Comportamento | nenhum — só `readonly` públicos | valida invariantes, tem métodos |
| Quem constrói | FormRequest (`toDTO()`) | o UseCase, a partir do DTO |

Por isso o DTO **nunca** entra em `app/Domain/`: o domínio recebe primitivos/VOs e não pode conhecer a forma do payload HTTP.

## Colunas do banco

Sempre em inglês e `snake_case`.

## Estrutura de arquivos

```
app/
├── Domain/
│   ├── Pricing/
│   │   ├── ProfitCalculator.php
│   │   ├── IncomeCalculator.php
│   │   ├── SalePriceCalculator.php
│   │   ├── OrderPayoutSplitter.php      # rateia o líquido de um pedido entre as keys entregues
│   │   ├── OfferCalculator.php          # TF2 keys a oferecer a um supplier por margem alvo
│   │   ├── ComparisonAlgorithm.php      # reprecificação vs. concorrentes (dumpers, bots, wholesale)
│   │   ├── ComparisonResult.php / OfferData.php
│   │   ├── MinMaxPriceCalculator.php
│   │   ├── MinimumMarginPolicy.php     # fonte única do piso de preço (min_api)
│   │   └── ValueObjects/MarketplaceFee.php
│   ├── Keys/
│   │   ├── KeyEligibility.php          # regra dos 21 dias
│   │   └── KeyDefaults.php             # estado inicial canônico de uma key nova
│   ├── Platform/
│   │   └── PlatformIdentifier.php      # regex Steam, EA, EGS, GOG, Xbox, PSN
│   ├── Bundles/
│   │   ├── BundleTypeResolver.php
│   │   └── BundleGameLookup.php
│   ├── Games/
│   │   └── GameNameNormalizer.php       # espelha o clearString do price-researcher
│   ├── Assets/
│   │   └── AssetAlert.php               # limiar de alerta de variação de câmbio
│   ├── Trades/
│   │   ├── CommentPolicy.php            # decide se recomenta um supplier (14 dias / jogos mudaram)
│   │   ├── TradeGameComparison.php
│   │   ├── TradeLineBuilder.php         # monta a linha a partir da saída do price_researcher
│   │   ├── TradeLineValue.php           # normalização por campo — compartilhada backfill/aba
│   │   ├── LegacyTradeLine.php          # converte uma entrada do JSON legado em linha (backfill)
│   │   └── ImportReadinessPolicy.php    # quando a trade pode virar keys
│   ├── Financial/                        # livro-caixa em R$ (≠ Sales/, dashboard de vendas em €)
│   │   ├── Money.php                     # centavos inteiros — reconciliação exata
│   │   ├── AccountTransfer.php           # dupla partida; valor fechado ou % do saldo da origem
│   │   ├── PartnerDistribution.php       # saque dos sócios: um débito por sócio
│   │   ├── PartnerSplit.php              # divisão + centavo órfão no Sócio 1
│   │   ├── ManualMovement.php            # lançamento de uma linha só (income/expense/tf2_purchase)
│   │   ├── MovementLeg.php               # uma linha do extrato
│   │   ├── JustificationPolicy.php       # débito em caixinha exige justificativa
│   │   ├── MovementDeletionPolicy.php    # o que pode ser apagado (mês draft, não gerado, não opening)
│   │   └── FinancialMonthDefaults.php
│   └── Enums/
│       ├── Marketplace.php             # apenas Gamivo por enquanto
│       ├── KeyPlatform.php
│       ├── ClaimType.php
│       ├── KeyFormat.php
│       ├── SellPlatform.php
│       ├── OffersUpdateMode.php         # WeAreLowest / WeAreNotLowest
│       ├── PresenceFilter.php           # filled / empty — filtro por coluna preenchida
│       ├── SupplierCategory.php         # vip / blocked
│       ├── TradeImportBlocker.php       # o que impede uma trade de virar keys
│       └── OrderPayoutAttribution.php   # quanto do casamento linha↔key fechou numa baixa de venda
│
├── UseCases/
│   ├── Keys/                             # operações agnósticas de marketplace
│   │   ├── AlertExpiringKeysUseCase.php  # alerta diário de keys perto de expirar
│   │   ├── RegisterKeyUseCase.php        # único caminho de entrada de keys (exige uma Trade)
│   │   └── UpdateKeyUseCase.php          # edição inline; recalcula o lote da trade
│   ├── Assets/
│   │   ├── AlertDollarVariationUseCase.php  # cotação guardada do TF2 x cotação real
│   │   └── UpdateAssetPricesUseCase.php     # converte a partir da moeda âncora (currentCurrency)
│   ├── Games/
│   │   ├── ResolveSteamIdsUseCase.php    # descobre steam_id via price_researcher
│   │   ├── RegisterGamesUseCase.php      # lote transacional; duplicata é pulada, não aborta
│   │   └── UpdateGameUseCase.php         # deriva normalized_name e busca gamivo_id no estoque
│   ├── Marketplaces/                     # orquestrações específicas por marketplace
│   │   └── Gamivo/                       # quando vier outro: Eneba/, G2A/, etc.
│   │       ├── AutoSellUseCase.php           # agrupa por gamivo_id (FIFO); trava max_api de keys >= 8 meses
│   │       ├── RegulateMinApiUseCase.php     # recalcula min_api via MinimumMarginPolicy (07:30)
│   │       ├── UpdateSoldOffersUseCase.php   # casa linha do histórico com key entregue e rateia o líquido
│   │       ├── DTO/
│   │       │   └── OrderPayoutBreakdownDTO.php   # baixas de um pedido + como o líquido foi atribuído
│   │       ├── UpdateOffersUseCase.php       # reprecifica via ComparisonAlgorithm — 1min, passada única (sobe e desce)
│   │       └── UpdatePopularityUseCase.php   # scraping SteamCharts — migração Gamivo Fase 2
│   ├── Bundles/
│   │   ├── SyncBundlesFromApiUseCase.php
│   │   ├── CreateBundleUseCase.php       # cria + vincula os jogos na mesma transação
│   │   └── AddGamesToBundleUseCase.php   # recusa o lote quando nenhum jogo é novo
│   ├── Suppliers/
│   │   ├── ProspectSupplierUseCase.php       # avalia lucratividade + decide comentar (CommentPolicy)
│   │   ├── ExecuteSupplierListUseCase.php    # POST price_researcher /api/lists/run
│   │   └── FindNewSuppliersUseCase.php       # POST price_researcher /api/suppliers/find-new
│   ├── Trades/
│   │   ├── CreateTradeUseCase.php
│   │   ├── StoreListTradeUseCase.php
│   │   └── UpdateTradeUseCase.php
│   └── Financial/
│       ├── DTO/                              # input tipado, montado pelos FormRequests
│       │   ├── BootstrapFinancialMonthDTO.php
│       │   ├── RecordMovementDTO.php
│       │   ├── RecordTransferDTO.php
│       │   ├── RecordTf2AllocationDTO.php
│       │   └── DistributeToPartnersDTO.php
│       ├── CreateDraftFinancialMonthUseCase.php  # bootstrap — só o primeiro mês
│       ├── RecordMovementUseCase.php             # income/expense/tf2_purchase (uma linha)
│       ├── RecordTransferUseCase.php             # dupla partida; valor ou % do saldo
│       ├── RecordTf2AllocationUseCase.php        # verba do mês: Principal → Tf2
│       ├── DistributeToPartnersUseCase.php       # saque dos dois sócios
│       ├── DeleteMovementGroupUseCase.php        # apaga o lançamento inteiro pelo group_id
│       ├── CloseMonthUseCase.php                 # devolve a sobra do TF2 e abre o próximo draft
│       └── ReopenFinancialMonthUseCase.php
│
├── Mail/                               # um Mailable por alerta; destinatário sempre config('app.admin_email')
│
├── Services/
│   ├── Bundles/BundleService.php        # queries e escrita sobre bundles + pivot
│   ├── Sales/SalesDashboardService.php  # dashboard analítico de vendas em € (/financial)
│   ├── Keys/
│   │   ├── KeyCalculationService.php   # taxas com cache, conversão para VOs
│   │   └── KeyRepository.php           # queries complexas + paginate() com whitelist de filtros
│   ├── Games/
│   │   ├── GameService.php              # lookup/preenchimento de gamivo_id e steam_id
│   │   └── GameRepository.php           # paginate() com whitelist de filtros (IndexGamesRequest)
│   ├── Suppliers/SupplierService.php
│   ├── Trades/TradeService.php          # paginate() com filtros/sort/paginação; is_stocked scoped-to-page
│   ├── Financial/
│   │   ├── FinancialMonthService.php   # leitura (CQRS): saldos derivados, draft corrente, prefill de TF2
│   │   └── MovementRecorder.php        # escrita: grava as pernas com um group_id só, em transação
│   ├── ResourceService.php             # conversão de moedas para Assets
│   └── External/
│       ├── GamivoApiService.php
│       ├── GgDealsApiService.php        # cliente da API GG.deals (bundles ativos)
│       ├── CurrencyConversionService.php
│       └── SteamChartsService.php
│
├── Http/
│   ├── Controllers/
│   │   ├── Keys/
│   │   │   ├── KeyController.php       # leitura/edição/remoção — GET/PUT/DELETE /keys (sem criação)
│   │   │   └── KeySaleController.php   # autoSell, updateSoldOffers...
│   │   ├── Suppliers/SupplierController.php
│   │   ├── Financial/FinancialMonthController.php  # /financial-months — livro-caixa em R$
│   │   ├── GameController.php
│   │   ├── BundleController.php
│   │   ├── AssetController.php
│   │   ├── FeeController.php
│   │   └── TradeController.php
│   └── Requests/
│
└── Models/                             # Eloquent puro — sem lógica de negócio
    ├── Key.php         → keys
    ├── Game.php        → games
    ├── Bundle.php      → bundles
    ├── Supplier.php    → suppliers
    ├── Trade.php       → trades
    ├── Asset.php       → assets
    └── Fee.php         → fees
```

> `Services/Sales/` é o **dashboard analítico de vendas em €** (`SalesDashboardController`, `SalesDashboard.vue`, `/sales`); `Services/Financial/` é o **fechamento mensal em R$** (`/financial-months`). Os dois já dividiam só o prefixo do nome e agora nem isso.
