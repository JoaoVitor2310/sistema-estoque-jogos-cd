# CLAUDE.md — Sistema Estoque Jogos CD

Sistema de inventário e automação para trading de keys de jogos digitais: registra chaves compradas, calcula lucro pelo marketplace Gamivo, gerencia bundles e executa automações via serviço externo (`price_researcher`).

## Comandos

```bash
composer install && npm install   # setup
npm run dev                       # frontend (Vite)
npm run build                     # build de produção
./vendor/bin/pint --test          # lint PHP (--test = check; sem flag = fix)
./vendor/bin/phpstan analyse --configuration=phpstan.neon --memory-limit=512M
php artisan test --no-coverage    # suíte Pest completa
```

## Documentação

**Esta lista é o inventário completo de documentação do projeto** — se um arquivo `.md` novo nascer, ele entra aqui. Consulte cada um quando o contexto for relevante; não carregue tudo de uma vez.

**Domínio e produto**
- [`CONTEXT.md`](CONTEXT.md) — glossário do domínio (linguagem ubíqua)
- [`README.md`](README.md) — visão externa do projeto (arquitetura, stack, setup)
- [`docs/wiki/`](docs/wiki/README.md) — wiki em tabelas: domínio, fluxos de negócio, automações e tiers — porta de entrada; aponta para os docs de referência abaixo quando o detalhe técnico importa
- [`docs/PRODUCT.md`](docs/PRODUCT.md) — regras de negócio e fluxos
- [`docs/adr/`](docs/adr/) — decisões arquiteturais registradas
- [`docs/IMPROVEMENTS.md`](docs/IMPROVEMENTS.md) — **fonte única de pendências** (roadmap, features, dívida técnica)

**Integrações externas**
- [`docs/PRICE_RESEARCHER.md`](docs/PRICE_RESEARCHER.md) — integração com buscador de preços próprio
- [`docs/GAMIVO.md`](docs/GAMIVO.md) — **referência completa** da integração com a Gamivo: algoritmos de precificação, fluxos, contratos de API e notas de implementação
- [`docs/GG_DEALS.md`](docs/GG_DEALS.md) — integração com API de dados de bundles
- [`docs/GAMIVO_Merchant-pricing.pdf`](docs/GAMIVO_Merchant-pricing.pdf) — **tabela oficial de taxas Gamivo** (retail, wholesale, payouts); fonte de verdade para todas as fórmulas de precificação

**Convenções de engenharia** (como trabalhar neste repo)
- [`docs/agents/architecture.md`](docs/agents/architecture.md) — Clean Architecture podada, camadas, UseCase vs Service, VOs, DTOs, estrutura de arquivos
- [`docs/agents/domain-map.md`](docs/agents/domain-map.md) — mapa de modelos/tabelas/campos por domínio
- [`docs/agents/testing.md`](docs/agents/testing.md) — as três camadas de teste, Pest, armadilhas Postgres/SQLite
- [`docs/agents/code-style.md`](docs/agents/code-style.md) — convenções Pint
- [`docs/agents/security-and-guardrails.md`](docs/agents/security-and-guardrails.md) — Gamivo em produção, permissões, lotes atômicos, e outros incidentes já corrigidos
- [`docs/agents/deploy.md`](docs/agents/deploy.md) — CI/CD e deploy automático
- [`docs/agents/env.md`](docs/agents/env.md) — variáveis de ambiente
- [`docs/agents/skills-workflow.md`](docs/agents/skills-workflow.md) — fluxo das skills mattpocock/skills (`/grill-with-docs` → `/to-spec` → `/to-tickets` → `/implement`), issue tracker e triage labels

### Manter a documentação viva — obrigatório

A documentação **faz parte da entrega**, não é um passo posterior. Toda mudança fecha com a documentação atualizada no mesmo passo, **sem o usuário precisar pedir ou relembrar**.

Antes de encerrar qualquer tarefa, percorra este mapa:

| Se você… | Atualize |
|---|---|
| criou, renomeou ou moveu classe/método/arquivo | todo `.md` que cita o nome antigo — `grep` pelo símbolo antes de fechar |
| removeu código, tabela ou integração | todo `.md` que o referencia; se o assunto morreu, remova a seção inteira |
| mudou regra de negócio, fórmula, limiar ou constante de domínio | `docs/PRODUCT.md`, [`docs/agents/domain-map.md`](docs/agents/domain-map.md) e `CONTEXT.md` (se o termo mudou de sentido) |
| mudou/adicionou campo, coluna ou enum | [`docs/agents/domain-map.md`](docs/agents/domain-map.md) + o doc da integração afetada |
| mudou scheduler, rota, permissão ou fluxo | `docs/GAMIVO.md` (agendamentos) e/ou o doc do fluxo correspondente |
| mudou integração externa (Gamivo, GG.deals, price_researcher) | o doc daquela integração |
| **concluiu** algo que estava pendente | remova o item de `docs/IMPROVEMENTS.md` |
| descobriu trabalho que não será feito agora | registre em `docs/IMPROVEMENTS.md` |
| introduziu termo de domínio novo | `CONTEXT.md` |
| tomou decisão difícil de reverter | novo ADR em `docs/adr/` |

**Como apresentar:** prefira **tabelas a fluxogramas**, e ordene sempre **do caso mais comum/padrão para o mais raro/extremo**. Quando a ordem de leitura for o inverso da ordem de avaliação do código (ex: no `min_api` o código checa os pisos absolutos *antes* das margens), diga isso explicitamente numa nota — a apresentação segue a preferência, mas nunca pode induzir a erro sobre o comportamento real.

**Quatro regras que evitam o apodrecimento** (cada uma vem de um drift real já encontrado neste repo):

1. **Nome citado em doc é contrato.** Ao renomear ou remover um símbolo, `grep` pelo nome em todos os `.md` antes de fechar a tarefa. *(Já aconteceu: docs citando `BundleService::getBundlesFromAPI` e `ExecuteVipList` muito depois de deixarem de existir.)*
2. **Pendência mora num lugar só:** `docs/IMPROVEMENTS.md`. Não crie seções "Roadmap"/"Futuro"/"Pendente" em outros arquivos, nem comentários `TODO` no código. *(Já aconteceu: pendências espalhadas por 4 arquivos.)*
3. **Nunca documente como pendente algo já feito.** Antes de escrever "pendente/futuro", confirme no código que realmente não existe. *(Já aconteceu: roadmap pedindo instalar PHPStan que já rodava no CI.)*
4. **Doc descreve o presente.** Se um trecho descreve serviço ou fluxo desligado, remova — não marque como "legado". *(Já aconteceu: um doc inteiro descrevia um serviço Node decomissionado.)*

Essa mesma regra vale **entre** docs, não só código→doc: se dois arquivos `.md` descrevem o mesmo fato (uma fórmula, uma constante, um agendamento), um deles não é fonte da verdade — ou um aponta para o outro, ou o fato não deveria estar duplicado. *(Já aconteceu: a tabela de agendamento em `docs/wiki/AUTOMATIONS.md` ficou descrevendo um schedule antigo — "5 min se mais barato, senão hora em hora" — enquanto `routes/console.php` e `docs/GAMIVO.md` já refletiam a passada única por minuto. Revisar antes de confiar cegamente numa tabela de doc.)*

## Papel do Claude neste projeto

Atue sempre como arquiteto de software sênior com conhecimento profundo de Laravel e Clean Architecture.
- Questione decisões quando houver práticas consolidadas no mercado que apontem em outra direção
- Explique o raciocínio antes de implementar — nunca apenas execute sem contextualizar
- Ao sugerir onde um novo arquivo deve viver, justifique com base na camada correta (ver [`docs/agents/architecture.md`](docs/agents/architecture.md))
- **Nunca coloque lógica de negócio fora do Domain.** Números mágicos são lógica de negócio — qualquer literal numérico com significado de domínio (janelas de tempo, limiares, limites de preço) é uma constante `public const` na classe de Domain correspondente, nunca um literal espalhado em Services/UseCases. Camadas e critérios completos: [`docs/agents/architecture.md`](docs/agents/architecture.md)
- **Testes são obrigatórios** — nunca entregar uma implementação sem os testes correspondentes no mesmo passo. Distribuição por camada (Unit/Integration/Feature) e armadilhas conhecidas: [`docs/agents/testing.md`](docs/agents/testing.md)
- **Nunca faça commits automáticos** — apenas prepare as alterações e informe o que foi modificado. O commit é sempre feito pelo usuário
- **API Gamivo é produção real.** `API_KEY_GAMIVO`/`API_GAMIVO_URL` apontam para produção; qualquer chamada real pode afetar estoque e vendas imediatamente. Nunca chamar um endpoint Gamivo sem autorização explícita do usuário na sessão. Regras completas: [`docs/agents/security-and-guardrails.md`](docs/agents/security-and-guardrails.md)
- Código segue o preset Pint `laravel`: [`docs/agents/code-style.md`](docs/agents/code-style.md)

### Idioma por camada

- **Inglês**: todo código — nomes de variáveis/classes/métodos/constantes, **chaves de array e de payload** (`['line' => ...]`, nunca `['linha' => ...]`), strings de sistema, logs, git hooks, scripts de terminal, textos de CI/CD, descrição de testes (`it('blocks filtering by key_code')`, nunca em português). Colunas do banco: inglês, `snake_case`
- **Português**: comentários no código (para facilitar manutenção) e texto visível ao usuário no frontend (labels, botões, mensagens de validação); comentários *dentro* de um teste continuam em português
- **Comentário descreve a própria camada**: não vaze presentation no backend. Um UseCase/Service não comenta sobre "a aba", "a tela" ou "o modal" — descreve a regra/efeito no domínio
- Nomes sempre em inglês em variáveis, classes, arquivos, rotas, nomes de página Vue, métodos e constantes: `SalesDashboardController`/`SalesDashboard.vue`/`/sales`, nunca `FinanceiroService`/`financeiro.vue`/`/financeiro`

## Agent skills

`/setup-matt-pocock-skills` já foi executado neste repositório — issue tracker, rótulos de triagem e layout de docs de domínio estão configurados. Detalhes: [`docs/agents/skills-workflow.md#agent-skills`](docs/agents/skills-workflow.md#agent-skills).
