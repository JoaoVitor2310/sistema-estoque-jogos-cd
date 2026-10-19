# Giveaways de jogo Steam viram tabela própria, ligada aos jogos pelo nome normalizado

**Status:** accepted — registro e vínculo implementados em 2026-10-18. A sinalização na trade e a aba Giveaways estão em [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md).

Quando um jogo sai de graça, o preço da key despenca em horas e leva meses para voltar. Comprar nesse intervalo sem saber quase sempre dá prejuízo, e é a causa de parte dos lotes que encalham ([`docs/adr/0013`](0013-flag-overstocked-games-in-trades.md)). O GamerPower tem uma API pública de giveaways, mas ela só mostra os **ativos agora**. O histórico precisa ser nosso.

A decisão: a tabela `giveaways` guarda um registro por giveaway, alimentado pela consulta `GET /giveaways?platform=steam&type=game` a cada 30 minutos (`SyncGiveawaysUseCase`). Cada giveaway é ligado em `giveaway_games` a **todas** as linhas de `games` com o mesmo nome normalizado. Um jogo é considerado em giveaway enquanto o giveaway está ativo e por uma janela depois do fim que cresce com a duração, em três faixas (`App\Domain\Games\GiveawayPolicy`; valores em [`docs/PRODUCT.md`](../PRODUCT.md#giveaway), a fonte da tabela).

Na negociação, o jogo em giveaway **sai da oferta ao fornecedor, mas continua como linha da trade** com o aviso — o mesmo desenho do jogo encalhado, e pelos mesmos motivos ([`docs/adr/0013`](0013-flag-overstocked-games-in-trades.md)): não compramos pelo preço normal o que acabou de sair de graça, e a equipe ainda vê o que apareceu na lista. Essa metade está decidida e ainda não implementada ([`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md)).

Os valores das faixas são a estimativa do negócio: o preço não volta quando o giveaway acaba, e quanto mais tempo o jogo ficou de graça (mais cópias distribuídas), mais demora. São constantes, e ainda não há histórico para calibrá-las.

## Considered Options

- **Tabela própria + pivot por nome normalizado** — escolhido.
- **Coluna booleana em `games`** — rejeitado. `games` só tem jogos que já passaram pelo sistema, e o caso perigoso é justamente o jogo novo que o fornecedor oferece. Um booleano também se apaga quando o giveaway acaba, e é aí que o preço continua no chão.
- **`giveaways.game_id` (FK para uma linha)** — rejeitado. Uma linha de `games` é o jogo **numa região** (`GameService` busca pelo par nome + região), e o giveaway derruba o preço em todas. Com uma FK só, Ballionaire EU e ROW ficariam sem o aviso.
- **Criar a linha de `games` (região global) para o jogo que nunca tivemos** — rejeitado. A marca da trade casa pelo nome de qualquer jogo, porque `trade_lines` não tem `game_id`. Criar a linha não simplificaria nada e traria custos: o `ResolveSteamIdsUseCase` passaria a pesquisar no SteamCharts jogos que nunca vamos comprar, a aba Games encheria de títulos alheios, e a linha afirmaria uma key global que não sabemos se existe.
- **Fim pelo `end_date` da API** — rejeitado. Muitos giveaways vêm com `"N/A"`, e os que duram "enquanto houver estoque" acabam antes do anunciado. O fim real é a primeira consulta bem-sucedida em que o giveaway some do feed; `end_date` fica só como informação.
- **Feed inteiro (`type=game`, todas as plataformas)** — rejeitado. Vinha cheio de DLC, Epic, itch.io, DRM-free, mobile e VR. Só a key Steam é o nosso mercado.
- **Gravar `users` para calibrar as faixas** — rejeitado. O número não tem definição oficial e parece contar só quem abriu o giveaway pelo próprio GamerPower. Seria coluna sem consumidor.

## Consequences

- **O histórico começa no deploy.** Giveaway que encerrou antes da primeira sincronização não existe para o sistema, mesmo que o preço ainda esteja baixo. O negócio aceitou isso: não há cadastro manual.
- **Consulta que falha não muda nada.** `GamerPowerApiService` distingue `[]` (a API respondeu 201, não há nenhum ativo) de `null` (falha). Só o primeiro encerra giveaways, porque tratar queda da API como feed vazio encurtaria todas as janelas.
- **O fim tem a precisão do intervalo.** Com uma passada a cada 30 minutos, o `last_seen_at` de um giveaway encerrado fica até 30 minutos antes do fim real.
- **O vínculo é refeito a cada passada enquanto o giveaway está na janela.** O `sync()` pega a região cadastrada depois e solta o jogo renomeado. Fora da janela, o vínculo congela.
- **O casamento depende do `normalized_name` de `games`.** Todo caminho que cria ou renomeia jogo grava esse campo (`RegisterGamesUseCase`, `UpdateGameUseCase`, `GameService::createGameIfDontExists`, `SyncBundlesFromApiUseCase`). Um caminho novo que esqueça isso deixa o jogo fora do vínculo sem erro nenhum.
- **Título fora do padrão fica sem jogo.** O nome é extraído do `title` (`GiveawayTitle`), e um formato novo é gravado sem vínculo e com log, em vez de sumir.
- **Cobertura limitada ao GamerPower.** Giveaway que o site não lista passa despercebido. A detecção por queda do preço pesquisado continua registrada em [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md) como complemento.
