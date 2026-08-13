# Skills de engenharia (mattpocock/skills)

Instaladas em `.claude/skills/` (symlinks) → `.agents/skills/` (conteúdo real), do repositório [mattpocock/skills](https://github.com/mattpocock/skills). Orquestram *como* o trabalho é conduzido (entrevista → spec → tickets → implementação → revisão) — **não substituem nenhuma convenção do [`CLAUDE.md`](../../CLAUDE.md)** (arquitetura, testes em 3 camadas, idioma por camada etc.), operam dentro delas. Quando `/implement` ou `/tdd` rodar testes, deve seguir a distribuição Unit/Integration/Feature de [`testing.md`](testing.md), nunca inventar a própria.

**Setup:** `/setup-matt-pocock-skills` já foi executado neste repositório — tracker de issues, rótulos de triagem e layout de docs de domínio estão configurados na seção "Agent skills" abaixo.

**⚠️ Colisão de nome:** este pacote instala uma skill própria chamada `code-review`, que **sobrepõe** o `/code-review` nativo do Claude Code. Neste repositório, `/code-review` agora roda a versão do mattpocock: duas revisões em paralelo (Standards + Spec) contra um ponto fixo (commit/branch/PR) — não mais a revisão de efficiency/correctness por nível de esforço.

## Fluxo principal — ideia → entrega

1. **`/grill-with-docs`** — entrevista para lapidar a ideia; mantém estado em `CONTEXT.md`/ADRs. Ponto de partida padrão (há codebase). *(Sem codebase → `/grill-me`, mesmo motor `/grilling`, mas sem persistir nada.)*
2. Se alguma pergunta só se resolve rodando código (UI, modelo de estado, lógica) → desviar para `/prototype`, entrando/saindo com `/handoff`.
3. O trabalho cabe numa sessão?
   - **Não** (multi-sessão) → `/to-spec` (vira spec) → `/to-tickets` (quebra em tickets com dependências declaradas) → `/implement` **por ticket**, limpando o contexto entre eles.
   - **Sim** → `/implement` direto, na mesma janela.

   Em ambos os casos, `/implement` roda `/tdd` internamente (um ciclo vermelho-verde por fatia) e fecha com `/code-review` antes de commitar — lembrando: nunca commitar sem o usuário pedir, por instrução do `CLAUDE.md`.

   **Higiene de contexto:** manter os passos 1–3 na mesma janela sem compactar — só depois do `/to-tickets`. Cada `/implement` recomeça do zero, a partir do ticket.

## Pontos de entrada (on-ramps)

- **Bugs/pedidos chegando de fora** → `/triage` (só para o que não foi criado por nós — issues, bug reports; tickets que já saíram de `/to-tickets` **não** passam por triage).
- **Algo quebrado, difícil de reproduzir** → `/diagnosing-bugs` — exige um loop de feedback apertado (um comando que já falha nesse bug específico) antes de teorizar.
- **Esforço gigante e nebuloso** (feature enorme, greenfield) → `/wayfinder` — mapeia decisões como tickets no tracker, resolve uma de cada vez; ao final, converge em `/to-spec` como as demais.

## Saúde do código

- **`/improve-codebase-architecture`** — rodar periodicamente (a cada poucos dias); escaneia oportunidades de "deepening" e gera relatório HTML. Escolher uma oportunidade alimenta o fluxo principal em `/grill-with-docs`.

## Vocabulário (usado por outras skills)

- **`/domain-modeling`** — lapida a linguagem ubíqua do projeto (termos, ADRs para decisões difíceis de reverter).
- **`/codebase-design`** — vocabulário de módulos profundos (interface, seam, profundidade) para desenhar a forma de um módulo.

## Cruzando sessões

- **`/handoff`** — compacta a conversa atual num arquivo para uma sessão nova referenciar. Usar quando quiser sessão nova mas preservar o raciocínio.
- **`/compact`** (nativo) — resume na mesma conversa; usar em pausas intencionais entre fases, nunca no meio de uma.

## Standalone

- **`/grill-me`** — mesma entrevista do `/grill-with-docs`, mas sem codebase/persistência.
- **`/prototype`** — protótipo descartável para responder uma pergunta de design (estado, lógica ou UI).
- **`/research`** — pesquisa delegada a um agente em background, com fontes citadas; alimenta o fluxo principal.
- **`/teach`** — ensina um conceito ao usuário ao longo de várias sessões.
- **`/writing-great-skills`** — referência para escrever/editar skills.

## Tabela de referência

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

Pendências vivem como seções dentro de `docs/IMPROVEMENTS.md` — sem GitHub Issues, sem tracker externo. See [`docs/agents/issue-tracker.md`](issue-tracker.md).

### Triage labels

Default canonical labels (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See [`docs/agents/triage-labels.md`](triage-labels.md).

### Domain docs

Single-context layout — `CONTEXT.md` + `docs/adr/` at the repo root. See [`docs/agents/domain.md`](domain.md).
