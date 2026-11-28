# O supplier entrega as keys pelo chat, e o chatbot as grava pela API

**Status:** accepted — implementado em 2026-11-27: `PATCH /api/suppliers/{steamId}/trades/{number}/keys` e `POST /api/suppliers/{steamId}/trades/{number}/deliver`. Contrato em [`docs/CHATBOT.md`](../CHATBOT.md).

Até aqui o supplier só entregava pela página do [ADR 0008](0008-supplier-fills-trade-through-tokenised-link.md): o chatbot mandava o link e o código, e ele preenchia as keys lá. Alguns suppliers não clicam em link, com medo de golpe. Os sócios decidiram em 2026-11-27 ([ADR 0006 do chatbot](../../../chatbot-steam/docs/adr/0006-supplier-delivers-keys-in-the-chat.md)) que o supplier passa a colar as keys no próprio chat da Steam, num modelo com os jogos da oferta, e que o chatbot deixa de mandar o link e o código.

A decisão deste lado: **um segundo canal de entrega, pelo mesmo caminho de escrita da página.** O chatbot manda pares (linha da trade, key) e, depois da confirmação do supplier, pede a entrega. Quem grava e quem fecha é o Estoque:

| Passo | Rota | Equivale na página a |
|---|---|---|
| Preencher | `PATCH /api/suppliers/{steamId}/trades/{number}/keys` | o autosave de cada linha (`UpdateTradeLineUseCase` com `TradeLineAuthority::Supplier`) |
| Entregar | `POST /api/suppliers/{steamId}/trades/{number}/deliver` | o botão de entregar (`MarkTradeDeliveredUseCase`) |

Regras próprias do chat (`App\Domain\Trades\ChatDelivery`):

- **Só as linhas da oferta vigente recebem key**, endereçadas pelo id da linha da trade (`offer.lines[].line_id` na leitura), nunca pelo nome. Linha fora da oferta recusa o pedido inteiro.
- **A entrega é só completa**: toda linha da oferta precisa de key. Parcial é com a equipe.
- **O total acertado é o da oferta vigente**, gravado pelo Estoque e somado por centavo. No chat, mandar as keys é aceitar a oferta; o chatbot não informa valor nenhum. Substitui um `amount` digitado antes.
- **Jogo que a equipe apagou depois da oferta** (`trade_line_id` nulo, `line_id: null` na leitura) não é entregue nem entra no total: o chatbot o deixa fora do modelo.
- **Só a key** vem pelo chat. Região, bundle, validade e o recado ficam com a equipe na conferência.

## Considered Options

- **Rotas pelo par (supplier, número), com o mesmo caminho de escrita da página** — escolhido. O número já é o que o chatbot usa para a trade no chat (o modelo começa com `Keys for trade #N:`), e o SteamID na rota faz a trade de outro supplier dar `404` sem regra a mais.
- **Rotas pelo uuid** — rejeitado. O chatbot teria de resolver o número em uuid antes de cada escrita, e a conferência de que a trade é daquele supplier viraria responsabilidade dele.
- **Uma rota só, que grava e entrega** — rejeitado. O supplier confirma o que o bot entendeu antes de fechar, como na página; com uma rota só, a confirmação teria de acontecer antes de a key chegar ao Estoque, e o chatbot guardaria key em claro até lá — o que o ADR 0006 dele recusa.
- **O chatbot mandar o total aceito** — rejeitado. Repetiria do lado de lá um valor que o Estoque já tem, e o Estoque é o dono da oferta ([ADR 0016](0016-supplier-offers-recorded-as-immutable-records.md)).
- **Entrega parcial pelo chat** — rejeitado por ora: precisaria distinguir "não quero vender este" de "esqueci de colar este".

## Consequences

- **O caminho de escrita de linha continua único.** O alcance do supplier é o mesmo da página (`TradeLineAuthority::Supplier`); o pedido do chat só traz `key_code`.
- **A trade é travada** (`lockForUpdate`) no preenchimento e na entrega, para uma escrita não entrar numa trade que uma entrega acabou de fechar.
- **O aviso à equipe sai depois do commit** (`DB::afterCommit` em `MarkTradeDeliveredUseCase`): a entrega pelo chat fecha dentro de uma transação, e o e-mail não pode anunciar uma entrega desfeita.
- **A confirmação repetida não é erro para o supplier.** Ela volta como `409 already_delivered`, que o chatbot trata como sucesso, e não move o `delivered_at`.
- **A key nunca volta pela API.** O preenchimento responde só quais linhas ainda faltam (`missing_line_ids`).
- **Página e chat escrevem nas mesmas linhas.** Vale a última escrita por linha, e a primeira entrega fecha as duas. A página e o código continuam existindo, e a equipe os manda a quem pedir; a leitura da API continua devolvendo os dois.
