# Issue tracker: `docs/IMPROVEMENTS.md` (custom)

Pendências deste repositório vivem como seções dentro de um único arquivo, [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md) — não há GitHub Issues, não há tracker externo. O **título da seção (`## ...`)** é o identificador de cada item; não há número de ticket.

## Conventions

- Cada entrada é uma seção `## Título`, separada da próxima por `---`, com no mínimo **Onde** (arquivos/pastas afetados), **Ação** (o que fazer — pode ser checklist `- [ ]`) e **Origem** (de onde veio: sessão de skill, code-review, decisão datada).
- Ordem do arquivo: roadmap/qualidade/features primeiro, dívida técnica de code-review no fim.
- **Concluído → a seção é removida.** Não existe estado "fechado" dentro do arquivo — regra 3 do [`CLAUDE.md`](../../CLAUDE.md) ("nunca documente como pendente algo já feito"). O arquivo só existe para descrever o que falta.
- **Wontfix → a seção também é removida.** Mesma razão; o motivo da recusa fica na conversa/PR que decidiu, não persiste no arquivo.

## Triage state

Uma entrada **sem** linha `**Status:**` é, por padrão, **`ready-for-human`** — foi escrita por alguém do time com contexto suficiente para virar trabalho, como são todas as entradas existentes hoje.

A linha `**Status:**` só aparece enquanto um item não chegou nesse ponto — tipicamente um pedido bruto que `/triage` ainda está processando:

```markdown
## Título da pendência

**Status:** needs-triage

**Onde:** ...
```

Valores possíveis: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human` (ver [`triage-labels.md`](triage-labels.md); `wontfix` nunca aparece como Status — remove a entrada em vez de marcá-la). Assim que `/triage` decide se o item é `ready-for-agent` ou `ready-for-human` e a entrada tem Onde/Ação/Origem completos, a linha `Status:` permanece com esse valor final até a entrada ser implementada e removida.

## When a skill says "publish to the issue tracker"

Adicionar uma nova seção `## Título` a `docs/IMPROVEMENTS.md`, no bloco correspondente (roadmap/qualidade/features primeiro; dívida técnica de code-review no fim), com **Onde**/**Ação**/**Origem** preenchidos — ou só **Status: needs-triage** se ainda faltar contexto para preenchê-los.

## When a skill says "fetch the relevant ticket"

Ler a seção correspondente em `docs/IMPROVEMENTS.md` pelo título exato — não há número para referenciar.

## Wayfinding operations

Usado por `/wayfinder`. Sem hierarquia nativa de issues, mapa e filhos são representados por nível de heading dentro do mesmo arquivo:

- **Map**: uma seção `## <Esforço> (mapa)` com sub-seções `### Notes`, `### Decisions-so-far`, `### Fog`.
- **Child ticket**: uma sub-seção `### <título do filho>` aninhada sob o mapa, com **Onde**/**Ação**/**Origem** como qualquer entrada normal, mais um `**Tipo:**` (`research`/`prototype`/`grilling`/`task`) e um `**Status:**` (`claimed`/`resolved`) — substituem os campos de arquivo separado do modelo local-markdown.
- **Blocking**: uma linha `**Bloqueado por:** <título do filho bloqueador>` logo abaixo do título do filho. Desbloqueado quando todo bloqueador listado está `resolved`.
- **Frontier**: dentre os filhos do mapa, os sem `Bloqueado por` pendente e sem `Status: claimed` — o primeiro na ordem do arquivo vence.
- **Claim**: editar a sub-seção, `**Status:** claimed`, salvar antes de começar.
- **Resolve**: acrescentar a resposta em **Ação**, `**Status:** resolved`, e anexar um ponteiro de contexto nas Decisions-so-far do mapa.
