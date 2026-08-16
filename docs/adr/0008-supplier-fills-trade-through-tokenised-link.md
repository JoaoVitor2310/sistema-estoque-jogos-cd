# O supplier entrega a trade por link com token, escrevendo direto nela

**Status:** proposed — vira `accepted` quando a implementação entrar.
**Depende de:** a normalização de `trades.games` em `trade_lines` — **concluída** (2026-08-16). A
escrita por linha (`PATCH /trades/{trade}/lines/{line}`, com `TradeLineDTO` carregando quais colunas
o payload trouxe) já existe; a entrega entra nela com uma lista menor de colunas permitidas.

Hoje o supplier cola os `key_code` no chat da Steam e a equipe transcreve linha a linha na aba de Trades; com o volume atual, essa transcrição é o gargalo do fluxo de compra. A decisão é dar a cada trade um link próprio (`/deliveries/{uuid}`, UUIDv4) protegido por um token digitado, no qual o próprio supplier informa `key_code`, `region` e `expiry` por linha, mais o `tf2_qty` e uma observação livre da trade — e essa escrita **altera a trade diretamente**, em vez de criar uma submissão pendente para a equipe aprovar.

Escrever direto é seguro porque a trade não é fonte de verdade de estoque: nenhuma key entra sem alguém clicar em importar, e o import é atômico e humano (ver [`0004`](0004-recalculate-trade-on-key-edit.md)). O supplier mexe em dado de trabalho, nunca em estoque.

## Uma escrita, duas leituras

O `PUT` da aba e o POST da entrega chamam **o mesmo** caminho de escrita, que aplica um patch com escopo de autoridade: o supplier só alcança `key_code`, `region` e `expiry` de uma linha, mais `tf2_qty` e `supplier_notes` da trade; a equipe alcança tudo. Sobre `trade_lines`, esse escopo é uma lista de colunas permitidas por autoridade, e a linha é endereçada pela PK — não por posição num array.

Endereçar por posição seria um bug silencioso: se a equipe apagasse uma linha na aba enquanto a página do supplier está aberta, o índice enviado apontaria para outro jogo, a key entraria no jogo errado, o import resolveria o `gamivo_id` errado e **nada falharia**. Com PK, linha removida vira patch descartado explicitamente.

A **leitura**, porém, é deliberadamente separada em dois read models. O endpoint da entrega devolve apenas `id` e `name` de cada linha, mais os três campos que ele preenche — `market_price`, `popularity`, `bundle` e `gamivo_id` nunca são selecionados. Isso é a saída do `price_researcher`, ou seja, quanto cada jogo dele vale para nós: um supplier que enxerga esse número renegocia toda trade futura com ele na mão. O vazamento aqui não seria de um atacante eventual, seria para **todo** supplier, sempre.

A separação é estrutural de propósito. Com dois endpoints, devolver preço na página do supplier exige reescrever uma classe; com um endpoint e um `if ($isAdmin)`, basta inverter um booleano — e booleano invertido passa em code review. Pela mesma razão a projeção é sempre por lista explícita de colunas, e a resposta do patch passa pelo mesmo read model da leitura inicial: devolver o registro fresco depois de salvar é o atalho por onde esse tipo de vazamento costuma escapar.

## Considered Options

- **Submissão pendente (`TradeSubmission`) que a equipe aplica com um clique** — auditável, e link vazado não corrompe trade. Rejeitado: reintroduz o passo manual que a feature existe para eliminar, e o checkpoint humano já existe no import.
- **Uma única tela para os dois papéis, com o admin autenticado por outro meio no mesmo link** — proposta durante o desenho para evitar sincronizar dois lados. Rejeitado por dois motivos: não resolve o conflito de escrita (a causa são dois **escritores**, não duas telas), e transforma a barreira estrutural de exposição em condicional. Some ainda a bancada da aba (filtros, paginação, projeção de oferta em TF2, duplicação de linha, import) e faria dois sistemas de autenticação conviverem.
- **Construir a entrega sobre o `trades.games` em JSON, como estava** — rejeitado, e é o que motivou a normalização precedente. Identidade de linha, escopo de autoridade e resolução de conflito teriam de ser escritos à mão sobre um array posicional; sobre tabela, saem de graça. Seria escrever exatamente o código que se joga fora depois.
- **Segredo embutido na URL, sem token separado** — um segredo só. Rejeitado: URL vaza por canais fora do nosso controle (histórico do navegador, log de acesso do servidor e de proxies, header `Referer`, preview de link do chat da Steam). O corpo de um POST não passa por nenhum deles. A URL endereça; o token é o único segredo.
- **`trades.id` na URL** — rejeitado por ser sequencial: entregaria o volume de trades da operação a qualquer supplier e tornaria a página enumerável. Daí o UUID, e **v4** especificamente — v1/v7 embutem timestamp e recriariam a ordenação que o id sequencial expunha.
- **Token guardado encriptado, para a equipe reconsultar** — rejeitado em favor de hash: reenviar o token é o mesmo ato de dois cliques de enviá-lo pela primeira vez, e não vale trocar uma propriedade forte por isso.

## Consequences

- **O conflito entre os dois escritores deixa de exigir travamento otimista.** Sobre o JSON, qualquer gravação substituía o array inteiro, e uma edição da equipe com estado velho apagaria em silêncio as keys recém-entregues — o que exigiria confronto de versão e resposta 409. Sobre `trade_lines`, cada escrita toca as colunas de uma linha; o choque só existe se as duas partes editarem **o mesmo campo da mesma linha**, caso em que "o último vence" é aceitável. Isso só vale enquanto o autosave da aba gravar por linha; se algum dia ele voltar a mandar o conjunto inteiro, o problema volta junto.
- **A trade ganha estado derivado, sem coluna de status.** `delivery_uuid` nulo = negociação em aberto; preenchido com `delivered_at` nulo = link enviado, esperando o supplier; ambos preenchidos com `is_imported = false` = a fila de conferência, que passa a ser a view default da aba; `is_imported = true` = fim. A fila se esvazia sozinha no import — não existe "marcar como conferida".
- **`delivered_at` só é gravado por um botão explícito.** A página salva conforme ele digita (proteção contra queda de conexão no celular), mas entrega parcial não pode entrar na fila de conferência. Linha em branco significa "não entreguei este jogo"; quem limpa a trade antes do import é a equipe, e o import segue travando linha com nome e sem key.
- **A credencial morre no import.** Como a página exibe as keys já preenchidas — decisão consciente, para ele corrigir typo sozinho —, a janela em que um link vazado dá acesso a key resgatável vai até o `is_imported = true`. É a única contenção desse risco.
- **A sessão do token é escopada a uma entrega.** Acertar o token de uma trade não dá acesso a nenhuma outra; rate limit no POST do token por UUID **e** por IP (só por IP, um atacante distribui; só por UUID, dá para travar de propósito a entrega de um supplier legítimo).
- **Sem trilha de proveniência.** Optou-se por não registrar log de eventos agora; a conferência humana antes do import é a mitigação. Registrado em [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md).
- **`region` continua texto livre.** Ele é chave de busca em `GameService::getIdGamivo()`, e uma grafia divergente cria um Game órfão sem `gamivo_id` — mas isso só acontece no import, depois da conferência humana. Canonizar as regiões está registrado em [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md).
- **É a primeira tela do sistema cujo usuário não é a equipe.** Nasce em inglês, e a convenção passa a ser português nas telas internas, inglês nas telas de terceiros. Ela também precisa de identidade visível (marca, data da trade, contagem de jogos — nunca a identidade do supplier): um formulário anônimo pedindo key_code é indistinguível de phishing, e normalizar isso com os suppliers os torna alvo fácil de quem se passar por nós.
