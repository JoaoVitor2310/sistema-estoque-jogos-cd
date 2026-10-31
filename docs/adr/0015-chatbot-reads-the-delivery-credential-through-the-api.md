# O chatbot lê a credencial de entrega pela API

**Status:** accepted — implementado em 2026-10-30 (`GET /api/trades/{uuid}`).

Até aqui só a equipe logada via o código da entrega: ele fica à vista na aba Trades, e quem copia link e código para a conversa da Steam é uma pessoa ([ADR 0008](0008-supplier-fills-trade-through-tokenised-link.md)). Com a integração do chatbot, quem conversa com o supplier e manda o acesso à entrega passa a ser o chatbot — e ele não guarda cópia da trade, então precisa ler link e código do Estoque.

A decisão: a leitura de **uma** trade pelo uuid (`GET /api/trades/{uuid}`) devolve `delivery_url` e `delivery_code`, autenticada pelo segredo dos serviços (`EXTERNAL_SECRET`, `VerifySecret`). O código é o mesmo que a aba mostra (`Trade::readableDeliveryToken`), e some com o import, como a faixa da aba — a partir dele a credencial não vale mais.

## Considered Options

- **Devolver o código na leitura de uma trade** — escolhido.
- **Manter o código só na aba, e a equipe colá-lo** — rejeitado: devolve à equipe o trabalho que a integração existe para tirar dela, e o chatbot ficaria esperando alguém para responder ao supplier.
- **Devolver o código também na lista das trades abertas de um supplier** — rejeitado: a lista viraria um atalho para colher os códigos de todas as trades de uma vez. Quem precisa do código de uma trade a lê pelo uuid.
- **O chatbot emitir a sua própria credencial** — rejeitado: o ADR 0008 decidiu uma credencial por trade, sem reemissão; duas credenciais criariam a dúvida de qual está na conversa.

## Consequences

- **O segredo dos serviços passa a dar acesso aos códigos de entrega.** Quem tiver o `EXTERNAL_SECRET` lê a credencial de toda trade aberta, e a página de entrega exibe os `key_code` já preenchidos até o import. Como o segredo é o mesmo para todos os serviços ([`docs/agents/security-and-guardrails.md`](../agents/security-and-guardrails.md#serviço-que-chama-o-estoque-usa-um-segredo-só)), um vazamento em qualquer um deles expõe isso. A contenção é a do ADR 0008 — a credencial morre no import — e restringir `/api/*` à rede Docker, pendente em [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md).
- **A lista não traz acesso.** `GET /api/suppliers/{steamId}/trades` devolve só o resumo de cada trade; o código sai apenas trade a trade.
- **Nada do que diz quanto o jogo vale para nós sai pela API.** A leitura segue o desenho do ADR 0008: consulta com as colunas escolhidas à mão (`TradeApiReadModel`) e formato num Resource próprio (`ApiTradeResource`). A oferta vigente traz só jogo, região e o valor ofertado.
