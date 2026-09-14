# A listagem de trades não carrega as linhas

**Status:** accepted — implementado em 2026-08-20.

A aba de Trades manda, por página, **quantas linhas** cada trade tem (`lines_count`) — não as linhas. As linhas de uma trade vêm de `GET /trades/{trade}/lines` quando o usuário abre aquele card, uma vez por card.

Isto **reverte** o comportamento anterior, em que `TradeService::paginate` fazia `with('lines')` e as trades abertas nasciam expandidas.

**O colapso é só da tabela de jogos.** O cabeçalho da trade fica à vista sempre: título, data, canal de compra, fornecedor (ou bundle, na compra direta), quantidade de TF2, os selos de estado, "Mensagem enviada" e o par link + código da entrega. A linha compacta que as importadas usavam foi removida — ela mostrava o título e a maioria das trades não tem um, então a lista virava uma coluna de "sem título". São os outros campos do cabeçalho que identificam uma trade, e são justamente os que não custam nada para renderizar.

## O problema era o DOM, não a query

Medido em 2026-08-20, contra o banco de produção (987 trades, 10.646 linhas):

| | antes | depois |
|---|---|---|
| listagem de 40 trades (query + presenter) | 100 ms | 32 ms |
| payload da listagem | 459 KB | 17,9 KB |
| linhas trafegadas na listagem | 2.509 | 0 |
| abrir a maior trade (380 linhas) | — | 8 ms / 67 KB |

Os 100 ms de servidor nunca foram o problema. Cada linha vira uma `<tr>` com 11 `<td>`, 9 `<input>`, 4 `<button>` e 3 colunas de tier: as 2.509 linhas somavam **~75 mil nós de DOM, ~22 mil deles `<input>`**, todos montados e reativos antes de alguém olhar para eles. Cinco trades da base passam de 300 linhas — uma sozinha rende 11 mil nós.

Por isso baixar `PER_PAGE` não resolveria: o custo é por linha, não por trade, e uma página com uma trade grande continuaria travando.

## Considered Options

- **Reduzir `PER_PAGE` de 40 para 10/20** — uma linha de mudança, mas trata o sintoma: uma única trade de 380 linhas continua travando a aba, e paginar mais miúdo piora a navegação. Rejeitado.
- **Virtualizar as linhas dentro do card** (renderizar só a janela visível) — resolveria o DOM sem mexer no payload, mas tabela virtualizada com input editável, autosave por célula e ordenação por coluna é complexa, e os 459 KB continuariam viajando. Rejeitado por custo/benefício; continua disponível se um card único de 380 linhas incomodar.
- **Carregar as linhas sob demanda** — escolhido. Ataca payload e DOM de uma vez, e a rota nova encaixa no grupo `trades/{trade}/lines` que já existia.

## Consequences

- **Os filtros continuam todos no servidor.** Inclusive a busca por jogo, que é `whereHas` sobre `trade_lines` — subquery dentro do SELECT de trades, não uma segunda ida ao banco. Nenhum filtro dependia das linhas estarem carregadas no navegador.
- **`GET /trades/{trade}/lines` devolve `key_code`.** Fica atrás do mesmo `CheckPermission` das demais rotas de linha, e do mesmo `scopeBindings`. Ser leitura não a torna mais frouxa: é a rota que entrega o ativo.
- **A ordenação por coluna reaplica ao carregar.** `sortRowsBy` ordena as linhas em memória; uma trade aberta depois disso chegaria na ordem do banco. Por isso `applyRowSort` roda também no fim do carregamento — sem ele a tela mostraria duas ordens ao mesmo tempo.
- **As linhas são buscadas uma vez por card.** Reabrir usa o que está em memória: rebuscar apagaria da tela edição ainda no debounce de gravação.
- **Falha no carregamento fecha o card de volta.** Card aberto e vazio pareceria trade sem jogos, e o usuário gravaria por cima de linhas que existem.
- **`+ Linha` e `Importar keys` só aparecem com a tabela aberta.** Os dois dependem das linhas, e `ImportReadinessPolicy` decide o import sobre a trade inteira: com a tabela fechada o botão só saberia dizer "não", e diria errado. `Excluir` continua sempre visível — não depende das linhas e já pede confirmação.
- **Os avisos de dado faltando nas linhas (key, preço, nome) só aparecem com a tabela aberta.** Os do cabeçalho — fornecedor ou bundle e TF2 em falta — seguem visíveis sempre, porque são campos da própria trade.
