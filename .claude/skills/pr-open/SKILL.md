---
name: pr-open
description: Prepara e abre um PR do Sistema Estoque. Separa os arquivos da mudança, confere que nada sensível vai para o repositório público, roda as mesmas verificações do CI no container (Pint, PHPStan, Pest, vue-tsc, Vitest), roda a pr-review e corrige o que ela recomendar. Depois escreve o título e a descrição num arquivo, lista as imagens de antes e depois quando a tela muda, cria o branch, prepara o stage e entrega o comando de commit para o dono rodar. Com o commit feito, faz o push, abre o PR (ou atualiza a descrição), posta os comentários das imagens e liga o auto-merge quando a regra da main exige os checks do CI — o PR entra na main, e em produção, quando o CI passa. Nunca faz commit e nunca chama a Gamivo. Use quando o usuário pedir "prepara o PR", "abre o PR", "vamos abrir o PR", "escreve a descrição do PR", "atualiza a descrição do PR", "como divido os commits" ou invocar /pr-open.
---

# Abrir o PR: Sistema Estoque

A skill tem um objetivo: **abrir um PR que passa no CI e que a revisão não vai barrar**, com o dono fazendo só o commit.

Três regras valem para a skill toda:

- **O commit é do dono; o resto é da skill.** O `CLAUDE.md` só proíbe o commit (incluindo `--amend` e `commit-tree`). Branch, stage, push e PR ficam com a skill. O push e o PR só acontecem depois de o dono confirmar o título e a descrição que viu; se algo mudar depois disso, ela mostra de novo.
- **A skill não julga o código.** O julgamento é da [`pr-review`](../pr-review/SKILL.md): a skill não acrescenta, remove nem reclassifica achado. Mudar uma regra de revisão é editar a `pr-review` ou o documento de onde a regra vem.
- **Nada aqui chama a Gamivo.** As verificações rodam a suíte, que não toca a API real; nenhum comando de validação manual pode tocar ([`security-and-guardrails.md`](../../../docs/agents/security-and-guardrails.md)).

## Passo 1. Delimitar a mudança

```bash
git fetch -q origin main
git rev-parse --abbrev-ref HEAD
git log --oneline origin/main..HEAD
git status --porcelain
gh pr view --json number,title,url,state 2>/dev/null
```

- **Abertura ou atualização:** branch com PR aberto prepara uma descrição nova para `gh pr edit`; senão, a abertura.
- **Branch:** na `main`, ou num branch cujo PR já foi mergeado (`gh pr view` com `state: MERGED`), proponha um nome novo no padrão do histórico (`feat/...`, `fix/...`, `refactor/...`, `chore/...`, em inglês, kebab-case), criado a partir de `origin/main` no passo 5.
- **Os arquivos:** a lista sai do diff contra `origin/main` mais os não rastreados. O working tree costuma ter outra frente: um ajuste solto em `docs/`, um plano, o `package-lock.json` que o entrypoint do container reescreve. Pergunte uma vez, numa lista só, sobre os que estão em dúvida. O `package-lock.json` só entra quando o `package.json` mudou; fora disso, desfaça com `git checkout -- package-lock.json`. Arquivo a mais vai para o histórico público; arquivo a menos quebra o CI.
- **O repositório é público.** Confira que nenhum arquivo da lista tem segredo, token, `.env`, dump (`*.sql`, `dump-*`), `key_code`, código de entrega, e-mail pessoal, SteamID ou nome real de supplier. Fixture de teste usa dado inventado. Se achar, pare e mostre.

## Passo 2. Rodar as verificações do CI

O `ci.yml` tem cinco jobs. Rode os mesmos no container, nesta ordem (do mais rápido ao mais lento), e pare no primeiro que falhar:

```bash
docker exec app-cd ./vendor/bin/pint --test
docker exec app-cd ./vendor/bin/phpstan analyse --configuration=phpstan.neon --memory-limit=512M
docker exec app-cd npm run typecheck
docker exec app-cd npm run test
docker exec app-cd php artisan test --no-coverage
```

- O `app-cd` parado localmente é o normal: pergunte antes de subir (`docker compose up -d`), e ofereça parar no fim.
- Falhou: mostre o trecho do erro e corrija. Pint que falha se resolve com `./vendor/bin/pint <arquivos>`, que só reformata; diga quais arquivos mudaram.
- Depois de rodar, confira o `package-lock.json` de novo: o container pode tê-lo reescrito.
- **Working tree misturado:** se ele tem mudança de outra frente que o CI lê (PHP, `resources/js/`, config), o resultado não vale para o PR. Diga isso e rode depois do commit, num worktree limpo dentro do projeto, para o container enxergá-lo: `git worktree add --detach .pr/ci HEAD`, e no container `cd /var/www/html/.pr/ci && cp .env.example .env && composer install -q && php artisan key:generate && npm ci` antes dos comandos acima. Remova no fim com `git worktree remove .pr/ci`.
- **Nada que o CI lê:** PR só de `docs/`, `*.md` ou `.claude/` não roda os jobs; diga que não se aplicam.

## Passo 3. Revisar e corrigir

Invoque a `pr-review` com a lista de arquivos do passo 1 e mostre o parecer como ele vier. Daí:

| Conclusão | O que a skill faz |
|---|---|
| **Precisa de mudanças** ou **Pronto com ressalvas** | Corrige os Bloqueantes e as Atenções sem perguntar, e as Sugestões baratas. Para e pergunta só quando a correção exige uma decisão de negócio, ou quando o dono já disse que um achado fica — aí o motivo dele vai para "Open points" |
| **Pronto para merge** | Segue para a descrição |

Depois de uma correção, volte ao passo 2 e rode a `pr-review` de novo: a correção também passa pelo CI e pela revisão. No resumo final, diga o que foi corrigido e o que ficou de fora, com o motivo.

## Passo 4. Título e descrição

O merge é **squash**: o título do PR vira o commit na `main`, e o GitHub acrescenta o `(#N)`. Siga o histórico: Conventional Commits, inglês, minúsculo exceto nomes próprios, sem ponto final, dizendo o efeito e não a implementação — `feat: number trades per supplier for the chatbot`, `fix: keep the cached exchange rate when the api fails`.

A descrição é em inglês, para quem não acompanhou o trabalho: porquê, depois o quê, depois como. Termos do domínio no lugar de nome de classe sempre que der.

```markdown
## Why

<O problema em uma ou duas frases: o que a equipe, o supplier ou a operação via. O item do docs/IMPROVEMENTS.md que a mudança fecha, se houver.>

## What changes

- <Comportamento, em termos do domínio. Um bullet por mudança que quem revisa deve notar.>

## How it was verified

- CI checks run locally: Pint, PHPStan, vue-tsc, Vitest (<n> tests), Pest (<n> tests).
- <O teste que prova a mudança, pelo comportamento: "a retried request with the same request_uuid does not spend a new number".>
- <O que só se verifica à mão, do "Para testar à mão" do parecer: a tela, a trava no Postgres. Ou "No manual check needed".>

## Screenshots

<Só quando a tela muda: a lista dos estados. As imagens vão nos comentários, um por estado.>

## Deploy

<Só o que o deploy precisa além do merge. O deploy.yml já roda `migrate --force` e o build do frontend; diga quando a migração faz backfill ou trava tabela grande. Fora dele (docs/agents/deploy.md): variável nova no .env da VPS, `docker compose up -d` por mudança no compose ou no Dockerfile, reload do nginx, o que o price-cd ou o chatbot precisa publicar antes ou depois. Sem nada: "Nothing beyond the merge.">

## Open points

<Só com conteúdo: achado que o dono decidiu manter, com o motivo; pendência registrada no docs/IMPROVEMENTS.md.>

🤖 Generated with [Claude Code](https://claude.com/claude-code)
```

Sem lista de arquivos e sem checklist: o diff mostra os arquivos, e o CI é a checklist.

Grave a descrição em `.pr/<branch, com / trocado por ->.md` (a pasta é ignorada pelo git). Mostre ao dono — no app desktop, `SendUserFile` com `display: "render"`; no terminal, o conteúdo na resposta — junto com o título, e pergunte **uma vez** se ele confirma os dois. Esse sim autoriza o passo 6.

## Passo 4a. Imagens da tela (só quando a interface muda)

Vale para todo diff em `resources/js/Pages/`, `resources/js/components/`, `resources/js/locale/` ou `resources/views/` que muda o que alguém vê.

1. **Os estados.** Liste cada estado que o diff muda — vazio, erro, muitos itens, sem permissão, cada valor de um badge —, tirados das condições alteradas no código. Para cada um: a rota (`http://localhost/<rota>`), os dados locais que o reproduzem e se ele existia antes (só então há "antes").
2. **Os dados.** O banco local costuma ser o dump de produção, e as imagens vão para um repositório público. Nenhuma imagem mostra `key_code`, código de entrega, e-mail, SteamID ou nome real de supplier: escolha linhas que não os exibem, ou crie dado inventado para o estado. Na dúvida, pergunte.
3. **Quem tira cada imagem.**
   - **Antes:** o dono, porque exige rodar a `main`. A skill nunca troca de branch no working tree dele.
   - **Depois:** o agente, pelo navegador do app (`http://localhost`). Se a página pedir login, o dono entra pelo painel do navegador; o agente nunca digita senha.
   - **Nomes:** `<n>-<estado>-before.png` e `<n>-<estado>-after.png`, `<estado>` em kebab-case, gravados em `.pr/`.
   - Estado sem imagem pode ficar sem, com um motivo que vai para o comentário dele.
4. **Onde ficam.** Num branch órfão `pr-assets`, uma pasta por número de PR: a `main` não guarda imagem e o link segue valendo depois do merge. Subir é um commit (`git commit-tree`), então **quem roda é o dono**, depois de o PR existir:

   ```bash
   N=<número do PR>; IMGS=(<caminhos das imagens>)
   git fetch -q origin pr-assets 2>/dev/null
   (
     set -e
     tmp_index="$(mktemp)"; trap 'rm -f "$tmp_index"' EXIT
     export GIT_INDEX_FILE="$tmp_index"
     if git rev-parse -q --verify origin/pr-assets >/dev/null; then
       git read-tree origin/pr-assets; parent=(-p origin/pr-assets)
     else
       git read-tree --empty; parent=()
     fi
     for img in "${IMGS[@]}"; do
       git update-index --add --cacheinfo "100644,$(git hash-object -w "$img"),${N}/$(basename "$img")"
     done
     commit="$(git commit-tree "$(git write-tree)" "${parent[@]}" -m "screenshots: PR #${N}")"
     git show --stat --format= "${commit}"
     git push origin "${commit}:refs/heads/pr-assets"
   )
   ```

   O índice temporário vive só no subshell: o índice e o working tree do dono não mudam. Sem `origin/pr-assets`, o primeiro commit nasce sem pai e cria o branch. Variável antes de `:` vai sempre com `${}`, porque no zsh `$commit:r` é modificador. Push recusado: rode de novo desde o `fetch`, nunca `--force`.
5. **Os comentários.** Um por estado, em `.pr/`, com o link `https://github.com/JoaoVitor2310/sistema-estoque-jogos-cd/raw/pr-assets/<N>/<arquivo>`, postados no passo 6.

## Passo 5. Branch, commit e push

O normal é um commit só, porque o squash junta tudo. Proponha mais de um só quando ajuda quem revisa (uma refatoração sem mudança de comportamento separada da mudança que a usa), cada um com mensagem e lista exata de arquivos. Pule o que já está feito.

1. **Branch.** `git switch -c <branch> origin/main` quando o passo 1 pediu branch novo. As mudanças não commitadas vão junto, inclusive as de outra frente, que seguem fora do stage. Se o `switch` recusar por conflito, pare e diga ao dono; nunca `stash`.
2. **Stage.** Se o índice já tem arquivo de outra frente (`git diff --cached --name-only`), pare e pergunte: o `git add` não tira do índice o que já estava lá. Depois, `git add -- <arquivos do commit>` e mostre `git diff --cached --stat`.
3. **Commit.** Entregue o comando ao dono num bloco `bash` e espere ele dizer que commitou:

   ```bash
   git commit -m "<título do PR>" -m "Co-Authored-By: <a linha de coautoria da sessão>"
   ```

4. **Push.** Confira com `git log --oneline origin/main..HEAD` que o commit é o esperado e rode `git push -u origin <branch>`. Nunca `--force`; push recusado, pare e diga ao dono.

## Passo 6. Abrir o PR

1. **Confira o GitHub.** `git fetch -q origin <branch>` e `git rev-list --left-right --count origin/<branch>...HEAD` tem de dar `0 0`, e `git status --porcelain` não lista nenhum arquivo do PR. Local à frente: falta o push. Remoto à frente: alguém empurrou algo não revisado; pare e diga ao dono.
2. **Abra ou atualize.** `gh pr create --base main --head <branch> --title "<título>" --body-file .pr/<arquivo>.md`, como rascunho só se o dono pedir; ou `gh pr edit <N> --title "<título>" --body-file .pr/<arquivo>.md`.
3. **Imagens.** Entregue ao dono o comando do passo 4a já com o número do PR. Depois que ele confirmar, poste um comentário por estado com `gh pr comment <N> --body-file <arquivo>`.
4. **Auto-merge.** O PR entra na `main` sozinho quando o CI passa — e o merge na `main` é deploy. Ligue só se a regra da `main` exige os checks do CI:

   ```bash
   gh api repos/JoaoVitor2310/sistema-estoque-jogos-cd/rules/branches/main \
     --jq '[.[] | select(.type == "required_status_checks") | .parameters.required_status_checks[].context] | sort | join(",")'
   ```

   - Saiu `PHPStan,Pest,Pint,Vitest,vue-tsc` (os jobs do `ci.yml`): `gh pr merge <N> --auto --squash`. O título e a descrição confirmados no passo 4 são os do commit na `main`.
   - Saiu outra coisa, ou nada: **não ligue**. Sem a regra, o GitHub considera o PR pronto na hora e o auto-merge mergearia antes do CI. Diga ao dono o que falta na regra (Settings → Rules → "Protect main"). Job novo ou renomeado no `ci.yml` também precisa entrar na regra, senão ninguém o exige.
   - PR em rascunho, ou o dono pediu para não mergear: não ligue.
   - A regra exige o branch atualizado com a `main`. Se a `main` andou, o PR fica parado até alguém clicar em "Update branch" no PR; isso cria um commit no branch, então é do dono.

   Depois disso o app acompanha os checks; não fique consultando.
5. **Apague os rascunhos.** Com o PR aberto e os comentários das imagens postados, apague os arquivos deste PR em `.pr/`, sem perguntar: o que vale já está no GitHub, e uma atualização reescreve a descrição a partir do PR.
6. **Responda em poucas linhas:** o link do PR, o que ficou em "Open points", o que falta testar à mão e o que o deploy pede. Ofereça parar o `app-cd` se a skill o subiu.

O PR não é anunciado em lugar nenhum, e o parecer da revisão não é postado nele: o parecer é do autor.
