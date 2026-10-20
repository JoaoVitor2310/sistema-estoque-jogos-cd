# GamerPower API

## O que é

O [GamerPower](https://www.gamerpower.com) lista giveaways de jogos ativos. Quando um jogo sai de graça, o preço da key despenca e leva meses para voltar. Por isso registramos os giveaways de jogo Steam e os vinculamos aos nossos jogos. A regra de quanto tempo o jogo continua "em giveaway" está em [`docs/PRODUCT.md`](PRODUCT.md#giveaway).

Documentação oficial: https://www.gamerpower.com/api-read. A página lista os endpoints e os parâmetros, mas **não descreve os campos da resposta**. A tabela abaixo e a fixture `tests/Fixtures/gamerpower/giveaways.json` fazem esse papel. Na fixture, o item 3716 é uma resposta real; os demais são casos montados, marcados assim na `description`.

## Configuração

Não precisa de chave nem de variável de ambiente. A URL base fica fixa em `config/services.php` → `services.gamerpower.base_url` (`https://www.gamerpower.com/api`).

**Termos de uso:** a API é livre para uso comercial, mas exige um link ativo de atribuição para GamerPower.com em toda tela que mostrar esses dados. Também não se pode revender os dados. O limite é de 10 requisições por segundo, e nós fazemos uma a cada 30 minutos.

## Endpoint utilizado

### `GET /giveaways?platform=steam&type=game`

Devolve os giveaways **ativos agora**. Giveaway encerrado some da resposta, e a API não tem histórico.

| Param | Valor | Por quê |
|---|---|---|
| `platform` | `steam` | Só key Steam; Epic, itch.io, DRM-free, mobile e VR ficam de fora |
| `type` | `game` | Só jogo completo; `loot` (DLC e itens) e `beta` (playtests) ficam de fora |

Os dois valores são constantes de `GamerPowerApiService` (`PLATFORM`, `TYPE`). O timeout é de 30 segundos (`TIMEOUT_SECONDS`).

| Status | Significado | O que `activeSteamGames()` devolve |
|---|---|---|
| `200` | Lista de giveaways ativos | A lista traduzida |
| `201` | Nenhum giveaway ativo | `[]` (a sincronização encerra os que estavam ativos) |
| Outro, corpo que não é lista, falha de conexão | Erro | `null` (a sincronização não muda nada) |

### Campos da resposta

Cada item é um objeto. Só os campos gravados aparecem aqui:

| Campo da API | Exemplo | Coluna em `giveaways` |
|---|---|---|
| `id` | `3716` | `gamerpower_id` (chave do upsert) |
| `title` | `"Dwarven Realms (Steam) Key Giveaway"` | `title`; dele saem `game_name`, `normalized_game_name` e `store` |
| `worth` | `"$9.99"` ou `"N/A"` | `worth` (`9.99` ou null) |
| `gamerpower_url` | `"https://www.gamerpower.com/dwarven-realms-steam-key-giveaway"` | `gamerpower_url` (link de atribuição) |
| `published_date` | `"2026-07-16 13:15:06"` | `published_date` (sem data, vale a primeira vez que o vimos) |
| `end_date` | `"2026-10-31 23:59:59"` ou `"N/A"` | `end_date` (null para "N/A"); **só informativo** |

O que não gravamos: `type` e `platforms` (o filtro da consulta já garante os dois), `status` (o nosso é derivado do feed), `users` (número sem definição oficial, provavelmente quem abriu o giveaway pelo próprio GamerPower), além de `thumbnail`, `image`, `description`, `instructions`, `open_giveaway_url` e `open_giveaway`. A API não informa o fuso das datas, e elas são gravadas como chegam.

## Nome do jogo a partir do título

O GamerPower não manda o nome do jogo num campo próprio. `App\Domain\Games\GiveawayTitle` o extrai do `title`:

| `title` | `game_name` | `store` |
|---|---|---|
| Dwarven Realms (Steam) Key Giveaway | Dwarven Realms | Steam |
| Leaper (IndieGala) Giveaway | Leaper | IndieGala |
| Game (2013) (Steam) Giveaway | Game (2013) | Steam |
| Dwarven Realms Key Giveaway | Dwarven Realms | — |
| Mystery Free Weekend (fora do padrão) | — | — |

Só o final do título é recortado: o sufixo `Giveaway`, o `Key` antes dele e o último parêntese. O recorte vem **antes** do `GameNameNormalizer`, que tira o caractere `(` mas mantém o conteúdo. Título fora do padrão é gravado com `game_name` nulo, sem vínculo, e com um log de warning. O parser é ampliado quando surgir um formato novo.

## Fluxo de sincronização (`SyncGiveawaysUseCase::execute`)

A cada 30 minutos (`*/30 * * * *`, UTC, só em produção), numa transação:

| Situação do giveaway | Efeito |
|---|---|
| Veio no feed | Criado ou atualizado por `gamerpower_id`; `status = active`, `last_seen_at = agora` (também reativa um que tinha encerrado e voltou) |
| Estava `active` e não veio | `status = ended`; `last_seen_at` fica como estava, e é ele que marca o fim real |
| Consulta falhou (`null`) | Nada muda: encerrar tudo porque a API caiu encurtaria a janela de todos |

Depois disso, todo giveaway **ainda na janela** (`GiveawayPolicy::isFlagged`) é revinculado em `giveaway_games` às linhas de `games` cujo `normalized_name` é igual ao `normalized_game_name`, uma por região. O `sync()` refeito a cada passada pega a região cadastrada depois do giveaway e solta o jogo que foi renomeado. Fora da janela, o vínculo fica congelado como histórico.

Jogo que nunca tivemos **não** ganha linha em `games`: o giveaway fica sem vínculo até o jogo aparecer. Decisão em [`docs/adr/0014`](adr/0014-record-steam-giveaways-from-gamerpower.md).

Como o fim é detectado na primeira passada em que o giveaway some, o intervalo de 30 minutos também é a precisão com que o fim fica registrado.

## Onde os dados são usados

Na trade: o jogo em giveaway sai da oferta ao fornecedor e vira linha marcada (`trade_lines.is_giveaway`), consultado por `GiveawayService` direto pelo nome normalizado, sem passar pela pivot. O selo da trade só credita o GamerPower na dica. Regra em [`docs/PRODUCT.md`](PRODUCT.md#jogo-em-giveaway-é-sinalizado-na-trade).

Na aba Giveaways (`GET /giveaways` → `GiveawayController::index` → `Giveaways.vue`): a lista dos giveaways registrados, lida por `GiveawayRepository::forListing()`. O rodapé da aba é o **link de atribuição** exigido pelos termos, e cada giveaway leva ao seu `gamerpower_url`.
