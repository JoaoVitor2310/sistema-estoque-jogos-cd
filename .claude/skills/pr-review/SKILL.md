---
name: pr-review
description: Revisa uma mudança do Sistema Estoque (o branch atual com o working tree, ou um PR do GitHub pelo número) contra as regras escritas do próprio repositório — CLAUDE.md, docs/agents/ (arquitetura, testes, segurança, env, deploy), os ADRs, o CONTEXT.md, o docs/PRODUCT.md — e contra o item do docs/IMPROVEMENTS.md que a mudança resolve. Cobre a regra de negócio no Domain, a Gamivo em produção, permissões e lotes atômicos, concorrência e a diferença Postgres × SQLite, migrações, os contratos com o price_researcher e o chatbot, a tela, os testes e a documentação viva. Entrega um parecer com achados classificados (Bloqueante, Atenção, Sugestão) e uma conclusão (Precisa de mudanças, Pronto com ressalvas, Pronto para merge). Só lê: não altera arquivo, não faz nada no git e nunca chama a Gamivo. É a revisão que vale para PR neste repositório (não o /code-review). Use quando o usuário pedir "revisa a mudança", "revisa o branch", "faz um code-review", "revisa o PR #N", "review antes do PR" ou invocar /pr-review.
---

# Revisão de mudança: Sistema Estoque

A revisão responde uma pergunta: **a mudança pode entrar na `main` sem quebrar uma regra que o projeto já escreveu?** O CI já roda Pint, PHPStan, Pest, vue-tsc e Vitest; a revisão não repete o que eles pegam. O trabalho aqui é o que ferramenta nenhuma faz: regra de negócio fora do Domain, a trava que o SQLite não exercita, o teste que nunca dispara, o documento que ficou descrevendo o passado.

Três regras valem para a revisão toda:

- **Todo achado aponta para uma regra escrita**: uma seção do `CLAUDE.md` ou de um `docs/agents/*.md`, um ADR, uma regra do `docs/PRODUCT.md` ou um termo do `CONTEXT.md`. Achado sem regra que se possa citar é preferência e entra no máximo como Sugestão. Bug real é exceção: entra mesmo sem regra, marcado "(correção)".
- **A revisão só lê.** Não edita arquivo nem roda `git add`, `commit`, `checkout`, `stash` ou `reset`. Corrigir é um passo à parte, depois do sim do dono. Se o pedido já disse para corrigir ("revisa e corrige", ou a `pr-open` chamando), esse é o sim.
- **Nunca chama a Gamivo.** Nem para confirmar um achado. `API_KEY_GAMIVO` aponta para produção ([`security-and-guardrails.md`](../../../docs/agents/security-and-guardrails.md#api-gamivo-é-produção-real--nunca-chamar-sem-autorização-explícita)).

## Passo 1. O que está sendo revisado

| Pedido | Alvo | Como ler |
|---|---|---|
| sem argumento | o branch atual e o working tree, contra `origin/main` | `git fetch -q origin main`; `git diff origin/main` mais `git ls-files --others --exclude-standard`. Leia os arquivos do disco |
| lista de arquivos (vinda da `pr-open`) | só esses arquivos | Igual à linha acima, filtrado pela lista |
| `#N` ou `N` | o PR `N` | `gh pr view N --json title,body,baseRefName,headRefName,files`, `gh pr diff N`, `gh pr checks N`. Leia com `git show "origin/${HEAD_REF}:<caminho>"` — no zsh, variável antes de `:` vai sempre com `${}` |

Arquivo que não parece da mudança (um plano solto em `docs/`, um ajuste de outro assunto, `package-lock.json` alterado pelo entrypoint do container): liste e pergunte uma vez se entra, antes de revisar. O `package-lock.json` só entra quando o `package.json` mudou.

Escreva **numa frase** o que a mudança faz, nos termos do domínio. Ela volta no passo 3.9.

## Passo 2. As regras que valem

Leia na versão de `origin/main` (`git show origin/main:<arquivo>`). Se a mudança altera uma regra, leia também a versão nova e revise a alteração como parte do diff.

1. `CLAUDE.md`: papel, idioma por camada, a tabela "Se você… / Atualize" e as quatro regras contra o apodrecimento.
2. [`docs/agents/architecture.md`](../../../docs/agents/architecture.md) e o ADR 0001/0007: camadas, UseCase vs Service, VO, DTO.
3. [`docs/agents/testing.md`](../../../docs/agents/testing.md): as três camadas, onde o teste mora, as armadilhas.
4. [`docs/agents/security-and-guardrails.md`](../../../docs/agents/security-and-guardrails.md): cada seção é um incidente que já aconteceu.
5. Os ADRs de `docs/adr/` do assunto tocado, o `CONTEXT.md` e as regras do `docs/PRODUCT.md`.
6. O item do `docs/IMPROVEMENTS.md` que a mudança resolve. Quando ela o conclui, o diff **remove** o item: leia-o nas linhas removidas (`git diff origin/main -- docs/IMPROVEMENTS.md`). Sem item, use o pedido da conversa; sem nenhum dos dois, siga só com as regras.
7. Quando a mudança toca contrato com outro sistema: `docs/PRICE_RESEARCHER.md`, `docs/CHATBOT.md` e, se acessíveis, `../price-cd/` e `../chatbot-steam/docs/SISTEMA-ESTOQUE.md`. O código do outro sistema vale mais que o documento daqui.

## Passo 3. O que conferir

Leia **cada arquivo alterado inteiro**, não só o trecho do diff: o problema costuma estar em volta — o método vizinho que já fazia o mesmo, o outro criador de trade que ficou sem a mudança.

### 3.1 Camadas

- `app/Domain/` é PHP puro: sem Eloquent, `DB`, facades, `config()`, `now()`, `Carbon`, `Str::`. O PHPStan não pega isso; a revisão pega.
- Regra de negócio e **número mágico** (janela de tempo, limiar, limite de preço, margem) moram no Domain como `public const`, nunca como literal em Service, UseCase, Controller ou Vue (`CLAUDE.md`, "Papel do Claude").
- Escrita que orquestra passos é UseCase; leitura nunca é (architecture.md, "Quando usar UseCase vs Service direto"). Operação que ganhou uma segunda etapa virou UseCase no mesmo diff.
- Input de UseCase é DTO montado no `toDTO()` do FormRequest; DTO nunca entra em `app/Domain/`.
- Conta que o Vue refaz na tela mora em `resources/js/domain/`, e quando projeta uma regra PHP o fixture é compartilhado (testing.md, "Regras de distribuição").

### 3.2 O negócio

- O Estoque é **dono da decisão de negócio**: preço, oferta, lucro e aceite são decididos aqui, nunca no price_researcher nem no chatbot.
- Dinheiro em centavos inteiros nos cálculos de rateio e oferta; arredondamento como a regra do `docs/PRODUCT.md` diz (para baixo na oferta, sobra para o Sócio 1 no rateio).
- Taxa e fórmula de preço conferem com o [`docs/GAMIVO.md`](../../../docs/GAMIVO.md) e a tabela oficial (`docs/GAMIVO_Merchant-pricing.pdf`).
- Termo novo ou com sentido mudado está no `CONTEXT.md`.

### 3.3 Segurança e produção

Confira contra as seções do `security-and-guardrails.md` que o diff toca. As que mais aparecem:

- Nenhum caminho novo chama a Gamivo de teste, seeder ou comando sem guarda.
- Rota nova tem permissão (`CheckPermission`) ou está em grupo que tem; rota de máquina está no grupo do segredo certo (`VerifySecret`, um por consumidor) e atrás do `InternalNetworkOnly`, e entrou nos datasets de `ApiConsumerSecretsTest` e `InternalNetworkOnlyTest`.
- Lote é `whereIn` numa transação, não loop; `detach()` tem argumento; seleção de tabela vai no corpo do DELETE.
- Filtro de rota pública tem whitelist; a API não devolve código de entrega em lista.
- Config lida por `config()`, nunca `env()` em runtime; fallback escolhido pelo que a ausência causa.
- Serviço externo que falha não vira número plausível nem status HTTP enganoso.

### 3.4 Concorrência e repetição

- Pedido repetido (retry do price_researcher, duplo clique, `request_uuid` reenviado) não duplica trade, oferta nem movimento.
- Ler-depois-gravar sobre a mesma linha tem trava ou índice único. O modo da trava importa no Postgres e **o SQLite ignora**: veja a armadilha em testing.md e se há teste da consulta compilada com `PostgresGrammar`.
- Transação não envolve chamada de rede; efeito externo (e-mail, API) depois do commit não deixa o banco num estado que a falha desmente.
- Agendamento novo ou alterado em `routes/console.php`: `withoutOverlapping` quando duas execuções se atropelariam, e a tabela do `docs/GAMIVO.md` atualizada.

### 3.5 Banco e configuração

- Migração roda no Postgres com dado de produção: coluna `NOT NULL` em tabela existente tem default ou backfill; índice único considera as linhas soft-deletadas (ADR 0011); backfill é determinístico.
- SQL escrito à mão roda nos dois bancos (`LOWER(col) LIKE`, não `ILIKE`; sem comparar data com `''`).
- Variável de ambiente nova aparece em `config/`, no `.env.example` (que o CI copia) e no [`docs/agents/env.md`](../../../docs/agents/env.md).

### 3.6 Contrato com os outros sistemas

Rota, payload, status ou campo de `/api`, `/suppliers/prospect` ou `/trades/from-price-researcher` mudou: o `docs/CHATBOT.md` ou o `docs/PRICE_RESEARCHER.md` muda junto, e a ordem de deploy (quem publica primeiro) vai para o "Deploy" do PR. Campo removido ou renomeado é quebra para o outro lado — confira no código dele, se acessível.

### 3.7 Tela

Só quando o diff toca `resources/js/` ou `resources/views/`:

- Texto visível em português; nomes de página, rota e componente em inglês.
- Estados novos cobertos: vazio, carregando, erro, muitos itens, sem permissão.
- Valor exibido vem da regra (do backend ou de `resources/js/domain/`), não de conta solta no componente.

### 3.8 Testes da mudança

Não se revisa a suíte; confere-se que a mudança está coberta:

- Cada comportamento novo tem teste **na camada certa e no arquivo da unidade que ele exercita** (testing.md). Se quatro UseCases ganharam o mesmo campo, os quatro testam.
- Numa correção, existe um teste que falharia sem ela. Na dúvida, prove: reverta a linha, rode o teste, confirme o vermelho, restaure.
- O teste chega ao ponto que diz testar: guarda de transação sob `RefreshDatabase`, trava sob SQLite, asserção variádica do Pest, asserção negativa (`assertDatabaseMissing`, `->not->toThrow`) sem uma positiva ao lado.
- `git grep` pelo nome antigo de toda classe, método, rota ou campo removido ou renomeado, inclusive em `tests/`, `resources/js/` e `docs/`.

### 3.9 Documentação viva

Percorra a tabela "Se você… / Atualize" do `CLAUDE.md` contra o diff, linha a linha:

- Item concluído **removido** do `IMPROVEMENTS.md`; pendência descoberta registrada lá, e só lá (sem `TODO` no código).
- `domain-map.md`, `PRODUCT.md`, `CONTEXT.md`, o doc da integração e a wiki descrevem o código depois da mudança. Dois docs dizendo o mesmo fato: um aponta para o outro.
- Decisão difícil de reverter tem ADR.
- Idioma por camada: código, chaves de array, logs e descrição de teste em inglês; comentários e tela em português. Comentário não vaza a tela para o backend, nem conta a história da mudança ("agora", "antes era") — isso é do commit.
- Documento novo entrou no inventário do `CLAUDE.md`.

### 3.10 Tamanho

Releia a frase do passo 1. Se ela precisa de "e também", são duas mudanças: diga onde dividir, como Atenção, quando cada metade fica coerente sozinha. Mudança grande e coerente não é achado.

## Passo 4. Confirmar antes de escrever

1. O problema está no código como fica depois da mudança, não num trecho que o diff removeu.
2. "Duplicado", "sem chamador" e "ainda usa o nome antigo" vêm com o `git grep` que prova.
3. A regra citada foi relida agora, no arquivo.
4. Comportamento de Laravel, Pest ou Postgres se confirma na fonte (`vendor/`, só leitura) ou executando no container: `docker exec app-cd php artisan test --no-coverage <arquivo>`, ou duas sessões `docker exec -it db-cd psql` para concorrência. Se o `app-cd` estiver parado, pergunte antes de subir (`docker compose up -d`).
5. O que não se confirma é descartado. Três achados certos valem mais que dez com dois falsos.

## Passo 5. Classificar e concluir

| Nível | Quando |
|---|---|
| **Bloqueante** | Regra de negócio fora do Domain; ADR vigente contrariado; chamada à Gamivo sem guarda; rota sem permissão ou com o segredo errado; lote não atômico; perda ou duplicação de dado sob repetição ou concorrência; migração que quebra com dado de produção; contrato com outro sistema quebrado sem aviso; teste que finge cobrir a mudança; bug com cenário concreto |
| **Atenção** | Funciona, mas pede decisão do dono antes do merge: doc desatualizado, item do IMPROVEMENTS não removido ou pendência não registrada, caso de borda sem teste, regra mudada sem registro, número mágico, mudança que dá para dividir |
| **Sugestão** | Sem efeito em comportamento nem arquitetura: nome, comentário, simplificação pequena |

Entre dois níveis, o achado só sobe com um cenário de dano escrito em uma frase.

A conclusão sai da classificação, sem exceção:

- algum Bloqueante: **Precisa de mudanças**;
- nenhum Bloqueante e alguma Atenção: **Pronto com ressalvas**;
- só Sugestões, ou nada: **Pronto para merge**.

## Passo 6. O parecer

No chat, em português, neste formato. Não grave em arquivo, a não ser que o dono peça.

```markdown
## Parecer: <Precisa de mudanças | Pronto com ressalvas | Pronto para merge>

**Mudança:** <a frase do passo 1>
**Alvo:** <branch ou PR #N> · <n> arquivos · +<a> −<d> · regras de origin/main em <data>
**Item do IMPROVEMENTS:** <título | pedido da conversa | nenhum>

<Uma ou duas frases sobre o que decidiu a conclusão.>

### Bloqueantes
1. **<título curto>**: [Arquivo.php:42](app/...)
   - Regra: <arquivo e seção, ou ADR NNNN>
   - Consequência: <o que dá errado, com o cenário>
   - Correção: <o que fazer>

### Atenção
1. ...

### Sugestões
- [Arquivo.php:10](app/...): <uma linha>

### Para testar à mão
- <o que a suíte não cobre: a tela, a trava no Postgres, a chamada real que só o dono autoriza; ou "nenhum">

### O que está bem feito
- <dois ou três pontos que vale repetir>
```

Seção sem achados fica com "Nenhum.": quem lê sabe que ela foi conferida.

Termine oferecendo, em uma linha, corrigir os Bloqueantes e as Atenções. Quando o pedido já incluía corrigir, corrija: Bloqueantes e Atenções sempre, Sugestões quando forem baratas. Pare e pergunte só quando a correção exigir uma decisão de negócio (o que acontece com um dado, qual regra vale). Depois de corrigir, rode esta revisão de novo e diga o que ficou de fora e por quê.
