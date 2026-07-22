# CLAUDE.md — Sistema Estoque Jogos CD

## O que é este sistema

Sistema de inventário e automação para trading de keys de jogos digitais. Registra chaves compradas, calcula lucro pelo marketplace Gamivo, gerencia bundles e executa automações via serviço externo (`price_researcher`).

## Documentação complementar

Consulte quando o contexto for relevante. **Esta lista é o inventário completo de documentação do projeto** — se um arquivo `.md` novo nascer, ele entra aqui.

- [`CLAUDE.md`](CLAUDE.md) — este arquivo: convenções, domínios, arquitetura, regras de negócio
- [`CONTEXT.md`](CONTEXT.md) — glossário do domínio (linguagem ubíqua)
- [`README.md`](README.md) — visão externa do projeto (arquitetura, stack, setup)
- [`docs/wiki/`](docs/wiki/README.md) — **wiki** em tabelas: domínio, fluxos de negócio, automações e tiers — porta de entrada; aponta para os docs de referência abaixo quando o detalhe técnico importa
- [`docs/adr/`](docs/adr/) — decisões arquiteturais registradas
- [`docs/IMPROVEMENTS.md`](docs/IMPROVEMENTS.md) — **fonte única de pendências** (roadmap, features, dívida técnica)
- [`docs/PRODUCT.md`](docs/PRODUCT.md) — regras de negócio e fluxos
- [`docs/PRICE_RESEARCHER.md`](docs/PRICE_RESEARCHER.md) — integração com buscador de preços próprio
- [`docs/GAMIVO.md`](docs/GAMIVO.md) — **referência completa** da integração com a Gamivo: algoritmos de precificação, fluxos, contratos de API e notas de implementação
- [`docs/GG_DEALS.md`](docs/GG_DEALS.md) — integração com API de dados de bundles
- [`docs/GAMIVO_Merchant-pricing.pdf`](docs/GAMIVO_Merchant-pricing.pdf) — **tabela oficial de taxas Gamivo** (retail, wholesale, payouts); fonte de verdade para todas as fórmulas de precificação

### Manter a documentação viva — obrigatório

A documentação **faz parte da entrega**, não é um passo posterior. Toda mudança fecha com a documentação atualizada no mesmo passo, **sem o usuário precisar pedir ou relembrar**.

Antes de encerrar qualquer tarefa, percorra este mapa:

| Se você… | Atualize |
|---|---|
| criou, renomeou ou moveu classe/método/arquivo | todo `.md` que cita o nome antigo — `grep` pelo símbolo antes de fechar |
| removeu código, tabela ou integração | todo `.md` que o referencia; se o assunto morreu, remova a seção inteira |
| mudou regra de negócio, fórmula, limiar ou constante de domínio | `docs/PRODUCT.md`, a seção "Regras de negócio" deste arquivo e `CONTEXT.md` (se o termo mudou de sentido) |
| mudou/adicionou campo, coluna ou enum | seção "Domínios do sistema" deste arquivo + o doc da integração afetada |
| mudou scheduler, rota, permissão ou fluxo | `docs/GAMIVO.md` (agendamentos) e/ou o doc do fluxo correspondente |
| mudou integração externa (Gamivo, GG.deals, price_researcher) | o doc daquela integração |
| **concluiu** algo que estava pendente | remova o item de `docs/IMPROVEMENTS.md` |
| descobriu trabalho que não será feito agora | registre em `docs/IMPROVEMENTS.md` |
| introduziu termo de domínio novo | `CONTEXT.md` |
| tomou decisão difícil de reverter | novo ADR em `docs/adr/` |

**Como apresentar:** prefira **tabelas a fluxogramas**, e ordene sempre **do caso mais comum/padrão para o mais raro/extremo** — tabela permite comparar linhas lado a lado e a pessoa encontra primeiro o caso que mais acontece. Quando a ordem de leitura for o inverso da ordem de avaliação do código (ex: no `min_api` o código checa os pisos absolutos *antes* das margens), diga isso explicitamente numa nota — a apresentação segue a preferência, mas nunca pode induzir a erro sobre o comportamento real.

**Quatro regras que evitam o apodrecimento** (cada uma vem de um drift real já encontrado neste repo):

1. **Nome citado em doc é contrato.** Ao renomear ou remover um símbolo, `grep` pelo nome em todos os `.md` antes de fechar a tarefa. *(Já aconteceu: docs citando `BundleService::getBundlesFromAPI` e `ExecuteVipList` muito depois de deixarem de existir.)*
2. **Pendência mora num lugar só:** `docs/IMPROVEMENTS.md`. Não crie seções "Roadmap"/"Futuro"/"Pendente" em outros arquivos, nem comentários `TODO` no código. *(Já aconteceu: pendências espalhadas por 4 arquivos.)*
3. **Nunca documente como pendente algo já feito.** Antes de escrever "pendente/futuro", confirme no código que realmente não existe. *(Já aconteceu: roadmap pedindo instalar PHPStan que já rodava no CI.)*
4. **Doc descreve o presente.** Se um trecho descreve serviço ou fluxo desligado, remova — não marque como "legado". *(Já aconteceu: um doc inteiro descrevia um serviço Node decomissionado.)*

---

## Papel do Claude neste projeto

Atue sempre como arquiteto de software sênior com conhecimento profundo de Laravel e Clean Architecture.
- Questione decisões quando houver práticas consolidadas no mercado que apontem em outra direção
- Explique o raciocínio antes de implementar — nunca apenas execute sem contextualizar
- Nunca coloque lógica de negócio fora do Domain
- **Números mágicos são lógica de negócio** — qualquer literal numérico com significado de domínio (janelas de tempo, limiares, limites de preço) deve ser uma constante `public const` na classe de Domain correspondente (ex: `KeyEligibility::EXPIRY_ALERT_DAYS`, `KeyEligibility::BUNDLE_EXCLUSION_DAYS`, `MinMaxPriceCalculator::FLOOR`). Services e UseCases referenciam a constante, nunca o número diretamente
- Ao sugerir onde um novo arquivo deve viver, justifique com base na camada correta
- **Nomes sempre em inglês** — variáveis, classes, arquivos, rotas, nomes de página Vue, métodos e constantes. Nunca criar `FinanceiroService`, `financeiro.vue` ou rota `/financeiro` — o correto é `FinancialService`, `Financial.vue`, `/financial`
- **Idioma por camada**:
  - **Inglês**: todo código (nomes, strings de sistema, mensagens de erro, logs, git hooks, scripts de terminal, textos de CI/CD)
  - **Português**: comentários no código (para facilitar manutenção) e texto visível ao usuário no frontend (labels, botões, mensagens de validação)
- Colunas do banco sempre em inglês e snake_case
- Mantenha boas práticas (SOLID, Clean Code, Design Patterns)
- Identifique Code Smells e proponha soluções
- **Testes são obrigatórios** — nunca entregar uma implementação sem os testes correspondentes no mesmo passo. O projeto usa três camadas de teste, cada uma com responsabilidade distinta:

  | Camada | Localização | O que testa | Exemplo |
  |--------|-------------|-------------|---------|
  | **Unit** | `tests/Unit/Domain/` | Lógica pura de Domain — sem banco, sem framework, sem `app()` | `TradeGameComparison::hasChanged()` com arrays literais |
  | **Integration** | `tests/Feature/UseCases/` | Orquestração de UseCases — chama `app(UseCase::class)->execute()` direto, com banco real | `ProspectSupplierUseCase` retorna `last_commented_at` e `games_changed` corretos |
  | **Feature** | `tests/Feature/` | HTTP ponta a ponta — auth, validação, persistência, estrutura da resposta | `POST /suppliers/prospect` retorna 401 sem token |

  **Regras de distribuição:**
  - Lógica de comparação, cálculo ou decisão que vive no Domain → Unit test
  - Comportamento do UseCase (o que orquestra, o que persiste, o que retorna) → Integration test via `app()`
  - Contratos HTTP (status codes, campos da resposta, middleware) → Feature test via HTTP
  - Não duplicar: se a lógica já está coberta no Unit, o Feature test não precisa repetir todos os casos — só o caminho feliz e o erro principal
  - Padrão: Pest. Use `DB::table()` para seeds, nunca Factories quando o dado é simples.
- **Permissões são obrigatórias** — toda rota nova deve declarar explicitamente quem pode acessá-la. Perguntas a responder antes de registrar qualquer rota: (a) guest pode acessar? (b) requer autenticação (`RequireAuth`)? (c) requer `can-edit` (`CheckPermission`)? (d) requer admin (`CheckAdmin`)? Rotas de página usam `RequireAuth` (redirect para `/login`); rotas de API/mutação usam `CheckPermission` (retorna 403 JSON). Nunca deixar rota sem middleware assumindo que "ninguém vai acessar". Após adicionar rotas, adicionar testes de acesso em `tests/Feature/Security/GuestAccessTest.php` cobrindo: guest bloqueado, usuário autorizado liberado.
- **Validação com enums usa `Rule::enum()`** — nunca use `'in:valor1,valor2'` para validar um campo que tem enum correspondente. Use `Rule::enum(MinhaEnum::class)` no FormRequest. Assim a validação se mantém sincronizada automaticamente quando o enum crescer.
- **Nunca faça commits automáticos** — apenas prepare as alterações e informe o que foi modificado. O commit é sempre feito pelo usuário.

## Code style (Pint — preset Laravel)

O projeto usa o Pint sem `pint.json`, portanto aplica o preset `laravel` padrão. Todo código gerado deve já respeitar essas regras para não gerar diff desnecessário no `pint --fix`.

**Espaçamento e indentação**
- 4 espaços (sem tabs)
- Sem trailing whitespace; arquivo termina com `\n`
- Linha em branco após `namespace` e após o bloco de `use`
- Linha em branco antes de `return` quando há código acima — exceto quando o corpo do método tem só uma linha

**Chaves e quebras de linha**
- Chave de abertura de classe e método na **mesma linha** da assinatura (K&R style): `function foo(): void {`
- `if`, `foreach`, `while` sempre com chaves, mesmo para uma linha
- Chave de fechamento de classe/método em linha própria

**Arrays**
- Nunca alinhar `=>` com espaços extras — espaçamento simples: `'key' => $value`
- Arrays curtos (inline) sem espaço após `[` e antes de `]`: `['a', 'b']`
- Arrays multilinha: cada item em sua própria linha, vírgula trailing na última entrada

**Tipos e declarações**
- `declare(strict_types=1)` **não** é usado neste projeto (preset Laravel não o exige)
- Tipos nativos sempre que possível (`int`, `string`, `float`, `bool`, `array`, `?Type`)
- `return type` obrigatório em todos os métodos
- Propriedades de classe sempre tipadas

**Imports**
- Um `use` por linha, sem grupos
- Ordenados alfabeticamente dentro de cada bloco (classes, functions, constants)
- Sem `use` não utilizado

**Visibilidade e modificadores**
- Sempre declarar visibilidade (`public`, `protected`, `private`) em propriedades e métodos
- Ordem dos modificadores: `final`/`abstract` → visibilidade → `static` → nome

**Strings**
- Aspas simples por padrão; aspas duplas só quando há interpolação ou caractere especial que exija

**Operadores**
- Espaço antes e depois de operadores binários (`===`, `!==`, `+`, `-`, etc.)
- Sem espaço entre operador unário e operando (`!$flag`, `-$value`)
- **API Gamivo é produção real — nunca chamar sem autorização explícita.** `API_KEY_GAMIVO` e `API_GAMIVO_URL` apontam para o ambiente de produção. Qualquer chamada real à API Gamivo (criar oferta, atualizar preço, fazer upload de chave, etc.) pode ter efeito imediato no estoque e nas vendas. Regras:
  1. **Nunca executar um endpoint Gamivo sem o usuário autorizar explicitamente** naquela sessão.
  2. **Sempre que precisar de um produto/oferta para testar**, perguntar ao usuário qual pode ser usado — nunca assumir ou inventar.
  3. Em testes automatizados, usar sempre `Http::fake()` — jamais permitir que um teste chegue à API real.
  4. Em desenvolvimento local, preferir o endpoint `calculate-customer-price` / `calculate-seller-price` (somente leitura) para validar cálculos antes de qualquer PUT/POST.

---

## Skills de engenharia disponíveis (mattpocock/skills)

Instaladas em `.claude/skills/` (symlinks) → `.agents/skills/` (conteúdo real), do repositório [mattpocock/skills](https://github.com/mattpocock/skills). Orquestram *como* o trabalho é conduzido (entrevista → spec → tickets → implementação → revisão) — **não substituem nenhuma convenção deste arquivo** (arquitetura, testes em 3 camadas, idioma por camada etc.), operam dentro delas. Quando `/implement` ou `/tdd` rodar testes, deve seguir a distribuição Unit/Integration/Feature já definida acima, nunca inventar a própria.

**Setup:** `/setup-matt-pocock-skills` já foi executado neste repositório — tracker de issues, rótulos de triagem e layout de docs de domínio estão configurados na seção [Agent skills](#agent-skills) abaixo.

**⚠️ Colisão de nome:** este pacote instala uma skill própria chamada `code-review`, que **sobrepõe** o `/code-review` nativo do Claude Code. Neste repositório, `/code-review` agora roda a versão do mattpocock: duas revisões em paralelo (Standards + Spec) contra um ponto fixo (commit/branch/PR) — não mais a revisão de efficiency/correctness por nível de esforço.

### Fluxo principal — ideia → entrega

1. **`/grill-with-docs`** — entrevista para lapidar a ideia; mantém estado em `CONTEXT.md`/ADRs. Ponto de partida padrão (há codebase). *(Sem codebase → `/grill-me`, mesmo motor `/grilling`, mas sem persistir nada.)*
2. Se alguma pergunta só se resolve rodando código (UI, modelo de estado, lógica) → desviar para `/prototype`, entrando/saindo com `/handoff`.
3. O trabalho cabe numa sessão?
   - **Não** (multi-sessão) → `/to-spec` (vira spec) → `/to-tickets` (quebra em tickets com dependências declaradas) → `/implement` **por ticket**, limpando o contexto entre eles.
   - **Sim** → `/implement` direto, na mesma janela.

   Em ambos os casos, `/implement` roda `/tdd` internamente (um ciclo vermelho-verde por fatia) e fecha com `/code-review` antes de commitar — lembrando: nunca commitar sem o usuário pedir, por instrução deste arquivo.

   **Higiene de contexto:** manter os passos 1–3 na mesma janela sem compactar — só depois do `/to-tickets`. Cada `/implement` recomeça do zero, a partir do ticket.

### Pontos de entrada (on-ramps)

- **Bugs/pedidos chegando de fora** → `/triage` (só para o que não foi criado por nós — issues, bug reports; tickets que já saíram de `/to-tickets` **não** passam por triage).
- **Algo quebrado, difícil de reproduzir** → `/diagnosing-bugs` — exige um loop de feedback apertado (um comando que já falha nesse bug específico) antes de teorizar.
- **Esforço gigante e nebuloso** (feature enorme, greenfield) → `/wayfinder` — mapeia decisões como tickets no tracker, resolve uma de cada vez; ao final, converge em `/to-spec` como as demais.

### Saúde do código

- **`/improve-codebase-architecture`** — rodar periodicamente (a cada poucos dias); escaneia oportunidades de "deepening" e gera relatório HTML. Escolher uma oportunidade alimenta o fluxo principal em `/grill-with-docs`.

### Vocabulário (usado por outras skills)

- **`/domain-modeling`** — lapida a linguagem ubíqua do projeto (termos, ADRs para decisões difíceis de reverter).
- **`/codebase-design`** — vocabulário de módulos profundos (interface, seam, profundidade) para desenhar a forma de um módulo.

### Cruzando sessões

- **`/handoff`** — compacta a conversa atual num arquivo para uma sessão nova referenciar. Usar quando quiser sessão nova mas preservar o raciocínio.
- **`/compact`** (nativo) — resume na mesma conversa; usar em pausas intencionais entre fases, nunca no meio de uma.

### Standalone

- **`/grill-me`** — mesma entrevista do `/grill-with-docs`, mas sem codebase/persistência.
- **`/prototype`** — protótipo descartável para responder uma pergunta de design (estado, lógica ou UI).
- **`/research`** — pesquisa delegada a um agente em background, com fontes citadas; alimenta o fluxo principal.
- **`/teach`** — ensina um conceito ao usuário ao longo de várias sessões.
- **`/writing-great-skills`** — referência para escrever/editar skills.

### Tabela de referência

| Skill | Acionamento | Quando usar |
|---|---|---|
| `ask-matt` | Manual | Não sabe qual skill usar — router |
| `grill-with-docs` | Manual | Início do fluxo principal, com codebase |
| `grill-me` | Manual | Início do fluxo principal, sem codebase |
| `grilling` | Automático | Motor por trás dos dois acima |
| `to-spec` | Manual | Sintetizar conversa em spec |
| `to-tickets` | Manual | Quebrar spec em tickets com dependências |
| `wayfinder` | Manual | Esforço maior que uma sessão aguenta |
| `implement` | Manual | Executar spec/tickets com TDD embutido |
| `tdd` | Automático | Construir uma funcionalidade concreta, teste-first |
| `diagnosing-bugs` | Automático | Bug difícil, intermitente, regressão |
| `code-review` | Automático (ver colisão acima) | Revisar branch/PR contra padrões + spec |
| `codebase-design` | Automático | Desenhar/melhorar interface de um módulo |
| `improve-codebase-architecture` | Manual | Manutenção periódica de arquitetura |
| `triage` | Manual | Processar issues/PRs externos |
| `domain-modeling` | Automático | Fixar terminologia, registrar ADR |
| `prototype` | Automático | Validar modelo de estado ou UI |
| `research` | Automático | Delegar leitura/investigação |
| `handoff` | Manual | Compactar sessão para outra retomar |
| `teach` | Manual | Ensinar conceito ao longo de sessões |
| `resolving-merge-conflicts` | Automático | Resolver merge/rebase em andamento |
| `writing-great-skills` | Manual | Referência para escrever skills |
| `setup-matt-pocock-skills` | Manual | **Rodar 1x, antes de tudo** — já executado |

## Agent skills

### Issue tracker

Issues live as GitHub Issues in `JoaoVitor2310/sistema-estoque-jogos-cd`, managed via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default canonical labels (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context layout — `CONTEXT.md` + `docs/adr/` at the repo root. See `docs/agents/domain.md`.

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
3. `RegulateMinApiUseCase` (scheduler 07:30) — recalcula `min_api` de todas as keys não vendidas via `MinimumMarginPolicy`
4. `AutoSellUseCase` lista keys elegíveis na Gamivo, **agrupadas por `gamivo_id`** (exclui bundles com < 21 dias; keys ≥8 meses têm `max_api` travado no preço de listagem)
5. `UpdateSoldOffersUseCase` atualiza com dados de venda da API Gamivo
6. `UpdateOffersUseCase` reprecifica ofertas ativas contra concorrentes via `ComparisonAlgorithm` (detecção de price dumpers, bots concorrentes conhecidos, wholesale) — roda a cada 5 min quando somos os mais baratos (`OffersUpdateMode::WeAreLowest`, só sobe o preço) e a cada hora caso contrário (`WeAreNotLowest`, tenta recuperar posição)

### 2. Cálculo de lucro (`KeyCalculationService` + `Domain/Pricing`)

Gamivo tem 2 tiers de taxa para **game keys** (categoria padrão):

| Tier | Condição | % sobre preço | Taxa fixa | Fórmula `simulated_income` |
|------|----------|:---:|:---:|---|
| Baixo | `market_price < €8` | 6% | €0,25 | `price × (1 - 0.060) - 0.25` |
| Alto | `market_price ≥ €8` | 8% | €0,40 | `price × (1 - 0.080) - 0.40` |

Wholesale (mode 1/2): **3,5%** sem taxa fixa → divisor `1.035`.

> **Fonte oficial:** [`docs/GAMIVO_Merchant-pricing.pdf`](docs/GAMIVO_Merchant-pricing.pdf) — contém a tabela completa incluindo gift cards, software, PSN/Xbox e métodos de payout.

`min_api` = `individual_cost × 1.4–1.6` (tier por faixa de preço); `max_api` = `individual_cost × 8–30`.

### 3. Bundles
Agrupamento de jogos (`bundle` ou `choice`). Many-to-many com `Game` via `bundle_games`.

**Janela de exclusão de bundle:**

| Constante | Dias | Significado |
|-----------|------|-------------|
| `KeyEligibility::BUNDLE_EXCLUSION_DAYS` | 21 | **Janela de venda inicial do bundle.** Enquanto o bundle está "em cartaz" (< 21 dias desde o lançamento), ninguém comprou a key ainda — o `AutoSellUseCase` exclui essas keys pois o preço ainda está em queda livre. |

A **regra dos 21 dias** está implementada em `AutoSellUseCase` via `scopeWithoutRecentBundle`.

### 4. Assets (`Asset` → tabela `assets`)
Representa ativos de troca (ex: TF2 key). Campos: `price_euro`, `price_dollar`, `price_brl`.
Usado por `KeyCalculationService` para converter o custo da trade em euros.

### 5. Fees (`Fee` → tabela `fees`)
Taxas do marketplace. Campos: `name`, `preco`.
Chaves usadas: `gamivoPercentualMenor`, `gamivoFixoMenor`, `gamivoPercentualMaior`, `gamivoFixoMaior`.

### 6. Suppliers e Trades (`Supplier`/`Trade` → tabelas `suppliers`/`trades`)
- `Supplier` — fornecedor Steam. Campos: `steam_id`, `url`, `region`, `initial_offer_pct`, `is_added` (marcado manualmente como adicionado à lista de trade), `has_traded`, `category` (enum `SupplierCategory`: `vip` | `blocked`)
- `Trade` — registro de uma lista de jogos comentada/ofertada a um supplier. Campos: `supplier_id`, `list_code`, `last_commented_at`, `title`, `date`, `message_sent`, `tf2_qty`, `games` (JSON)
- Fluxo: `ProspectSupplierUseCase` avalia a lucratividade dos jogos do supplier (`IncomeCalculator` + `OfferCalculator`, margem `OfferCalculator::NEW_SUPPLIER_PROFIT_PERCENT` = 70%), decide comentar via `Domain/Trades/CommentPolicy` (recomenta se os jogos mudaram desde a última vez — `TradeGameComparison::hasChanged()` — ou se já passaram `CommentPolicy::INTERVAL_DAYS` = 14 dias sem comentário) e persiste um `Trade`
- `ExecuteSupplierListUseCase` → POST `price_researcher` (`/api/lists/run`) para rodar a lista de jogos do supplier
- `TradeService::isStocked()` / `allWithStockedStatus()` — indica se algum `key_code` da trade já está no estoque (`keys`)
- *Absorveu os antigos `Vip`/`VipList`* — tabelas `vips`/`vip_lists` e `ExecuteVipListUseCase`/`VipListExecutionService` foram removidos (migration `2026_07_05_000001_drop_vips_and_vip_lists_tables.php`); `VipList` virou `Trade` (`supplier_id` + `list_code`), `Vip.id_steam` virou `suppliers.steam_id`.

### 7. Autorização
- `AuthorizedUsers` — controla acesso (`can-edit`)
- Admin: `Gate::define('is-admin', fn($u) => $u->email === env('ADMIN_EMAIL'))`

---

## Arquitetura

**Clean Architecture podada** — domínio isolado e testável, sem boilerplate de repositories abstratos ou adapters. Sistema interno com dois usuários; nunca precisaremos trocar o framework.

> Hoje o sistema opera **exclusivamente na Gamivo**. As diretrizes de estrutura para um eventual segundo marketplace foram centralizadas em [`docs/IMPROVEMENTS.md`](docs/IMPROVEMENTS.md).

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
│   ├── Import/
│   │   ├── ExcelDateConverter.php
│   │   ├── ImportRowValidator.php
│   │   └── ImportHeaderValidator.php
│   ├── Bundles/
│   │   ├── BundleTypeResolver.php
│   │   └── BundleGameLookup.php
│   ├── Games/
│   │   └── GameNameNormalizer.php       # espelha o clearString do price-researcher
│   ├── Assets/
│   │   └── AssetAlert.php               # limiar de alerta de variação de câmbio
│   ├── Trades/
│   │   ├── CommentPolicy.php            # decide se recomenta um supplier (14 dias / jogos mudaram)
│   │   └── TradeGameComparison.php
│   └── Enums/
│       ├── Marketplace.php             # apenas Gamivo por enquanto
│       ├── KeyPlatform.php
│       ├── ClaimType.php
│       ├── KeyFormat.php
│       ├── SellPlatform.php
│       ├── OffersUpdateMode.php         # WeAreLowest / WeAreNotLowest
│       └── SupplierCategory.php         # vip / blocked
│
├── UseCases/
│   ├── Keys/                             # operações agnósticas de marketplace
│   │   ├── RegisterKeyUseCase.php
│   │   ├── UpdateKeyUseCase.php
│   │   └── ImportKeysFromXlsxUseCase.php
│   ├── Marketplaces/                     # orquestrações específicas por marketplace
│   │   └── Gamivo/                       # quando vier outro: Eneba/, G2A/, etc.
│   │       ├── AutoSellUseCase.php           # agrupa por gamivo_id (FIFO); trava max_api de keys >= 8 meses
│   │       ├── RegulateMinApiUseCase.php     # recalcula min_api via MinimumMarginPolicy (07:30)
│   │       ├── UpdateSoldOffersUseCase.php
│   │       ├── UpdateOffersUseCase.php       # reprecifica via ComparisonAlgorithm — 5min/1h por posição
│   │       └── UpdatePopularityUseCase.php   # scraping SteamCharts — migração Gamivo Fase 2
│   ├── Bundles/
│   │   └── SyncBundlesFromApiUseCase.php
│   ├── Suppliers/
│   │   ├── ProspectSupplierUseCase.php       # avalia lucratividade + decide comentar (CommentPolicy)
│   │   └── ExecuteSupplierListUseCase.php    # POST price_researcher /api/lists/run
│   └── Trades/
│       ├── CreateTradeUseCase.php
│       ├── StoreListTradeUseCase.php
│       └── UpdateTradeUseCase.php
│
├── Services/
│   ├── Keys/
│   │   ├── KeyCalculationService.php   # taxas com cache, conversão para VOs
│   │   └── KeyRepository.php           # queries complexas
│   ├── Games/
│   │   ├── GameService.php
│   │   └── GameRepository.php
│   ├── Suppliers/SupplierService.php
│   ├── Trades/TradeService.php          # is_stocked, listagem com supplier eager-loaded
│   ├── ResourceService.php             # conversão de moedas para Assets
│   └── External/
│       ├── GamivoApiService.php
│       ├── CurrencyConversionService.php
│       └── SteamChartsService.php
│
├── Http/
│   ├── Controllers/
│   │   ├── Keys/
│   │   │   ├── KeyController.php       # CRUD — rota: GET/POST/PUT/DELETE /keys
│   │   │   ├── KeyImportController.php
│   │   │   └── KeySaleController.php   # autoSell, updateSoldOffers...
│   │   ├── Suppliers/SupplierController.php
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

> Alguns Services documentados acima (ex: `BundleService`, `AssetService`, `FinancialService`) vivem hoje na raiz de `Services/` em vez de subpastas por domínio, e `FinancialService`/`FinancialController` (domínio financeiro, não coberto neste arquivo) nem estão listados aqui. Fora do escopo desta correção pontual — vale uma auditoria própria da árvore de `Services/`/`Controllers/` depois.

---

## Deploy

Deploy é **automático ao mergear na `main`**, via GitHub Actions (`.github/workflows/`):

| Workflow | Trigger | Jobs |
|----------|---------|------|
| `ci.yml` | push/PR em `main` | Pint · PHPStan · Pest (paralelos) |
| `deploy.yml` | `ci.yml` conclui com sucesso em `main` | Build frontend → SSH deploy → SCP `public/build` |

Fluxo: merge na `main` → `ci.yml` (Pint + PHPStan + Pest) → `deploy.yml` (build do frontend no runner → SSH na VPS: `git pull` + `composer install` se o lock mudou + `migrate` + caches → SCP do `public/build` para a VPS).

**Secrets** (GitHub → Settings → Secrets and variables → Actions): `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`, `VPS_PORT`.

> Todas as pendências do sistema (qualidade de código, features, dívida técnica) estão centralizadas em [`docs/IMPROVEMENTS.md`](docs/IMPROVEMENTS.md).

---

## Regras de negócio

- **Venda FIFO / agrupamento por `gamivo_id`** (`AutoSellUseCase`): keys do mesmo `gamivo_id` compartilham **uma única oferta** na Gamivo, então o `AutoSellUseCase` as processa em grupo — uma oferta, um `uploadKeys` em lote — em vez de repetir o ciclo `createOffer→updateOffer→uploadKeys→changeOfferStatus` por key (a repetição na mesma oferta causava o erro `400 "Wait for the current action to end"`). **Duas etapas, nessa ordem:** (1) a decisão de listar é tomada **por key** — cada key entra se o mercado cobre o `min_api` *dela* (o `min_api` já embute a idade, pois a `MinimumMarginPolicy` o rebaixa ao FLOOR para keys velhas); as demais são puladas individualmente. (2) Só então, **entre as keys aprovadas**, escolhe-se a **governante** — a mais antiga (**menor `id`**) — que define o `seller_price` único da oferta, pois a Gamivo vende **FIFO** (a primeira enviada vende primeiro). O upload envia as keys aprovadas em **ordem de `id` ASC**. Keys aprovadas mas não confirmadas na oferta seguem elegíveis na próxima rodada (marca só as confirmadas). *(O `findMinMaxByGamivoId`, que hoje agrega `MIN/MAX` para o `UpdateOffersUseCase`, será substituído futuramente por essa mesma regra de governante — ver `docs/IMPROVEMENTS.md`.)*
- **Regra dos 21 dias** (`KeyEligibility::BUNDLE_EXCLUSION_DAYS`): keys de jogos em bundles com < 21 dias são excluídas do `autoSell()` — o bundle ainda está em cartaz e o preço está em queda.
- **Age override — 8 meses** (`KeyEligibility::OLD_KEY_MONTHS`): a idade da key entra na listagem **apenas via `min_api`** — `MinimumMarginPolicy::minApi` rebaixa o `min_api` ao FLOOR para keys com ≥ 8 meses (persistido diariamente pelo `RegulateMinApiUseCase`, pré-requisito do `AutoSellUseCase`). O `AutoSellUseCase` **não reavalia a idade** para decidir listagem ou preço — consulta o `min_api`, que é a fonte única do piso. A única coisa que ele faz com a idade é **travar o `max_api`** no preço praticado nas keys individualmente velhas após a listagem, impedindo o `UpdateOffersUseCase` de subir o preço depois (a `MinimumMarginPolicy` conta com essa trava — ver o comentário na classe).
- **`min_api` — fonte única (`MinimumMarginPolicy`)**: `RegulateMinApiUseCase` (scheduler 07:30) recalcula `min_api` de todas as keys não vendidas, listadas ou não, todo dia. Piso incondicional (FLOOR) para: expiração em ≤ 30 dias, estoque comprado há ≥ 8 meses (`OLD_KEY_MONTHS` — sobrevive à listagem, nunca regride) e listada há ≥ 10 meses (limbo). Fora isso, margem percentual por tempo de estoque (não listada, 4/6 meses) ou por tempo listado (listada, 3/4/6 meses) — ver `MinimumMarginPolicy` para a árvore completa.
- **Tiers Gamivo**: fee diferente abaixo e acima de €8 (ver tabela na seção Domínios).
- **`max_api`**: calculado em `MinMaxPriceCalculator` com base no `individual_cost`.
- **`individual_cost` é imutável após registro**: no `UpdateKeyUseCase` nunca é recalculado.
- **Importação XLSX**: 10 colunas obrigatórias (A=Data, B=Preço mercado, C=URL perfil, D=Qtd. TF2, E=Bundle, F=Expiração, G=Popularidade, H=Region Lock, I=Chave, J=Nome do Jogo). Datas em formato serial do Excel são convertidas. `tf2_quantity = 0` é rejeitado.

---

## Variáveis de ambiente

```env
# Serviço externo de pesquisa de preços
API_PRICE_RESEARCHER=
DEV_API_PRICE_RESEARCHER=

# API Gamivo — chamada diretamente pelo Laravel
# API_GAMIVO_URL = base URL da API (ex: https://backend.gamivo.com)
# API_KEY_GAMIVO = Bearer JWT (expira — precisa rotacionar manualmente)
# Quando expirar: o sistema detecta UNAUTHORIZED_EXPIRED_TOKEN e envia e-mail de alerta
# ⚠️  PRODUÇÃO REAL — ver regras de segurança na seção "Papel do Claude neste projeto"
API_GAMIVO_URL=https://backend.gamivo.com
API_KEY_GAMIVO=

# Google OAuth
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=

# Sistema
ADMIN_EMAIL=carcadeals@gmail.com
EXTERNAL_SECRET=            # Bearer token exigido de serviços externos que chamam o Sistema Estoque
```
