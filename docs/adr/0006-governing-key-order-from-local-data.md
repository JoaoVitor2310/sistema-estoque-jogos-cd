# Governing key order comes from local data, not a live Gamivo lookup

Ao estender a regra da governante FIFO (ADR 0002) do `AutoSellUseCase` para o
`UpdateOffersUseCase`, consideramos consultar `GET /offers/{id}/keys/active/{offset}/{limit}`
na Gamivo para obter a ordem real das keys dentro da oferta, em vez de inferir a partir dos
nossos próprios dados. Rejeitamos essa opção: a API não documenta nenhuma garantia de
ordenação nesse endpoint, a chamada seria repetida por produto a cada ciclo de
reprecificação (a cada 5 minutos quando somos os mais baratos), e ainda exigiria casar o
`content` retornado com `keys.key_code` para localizar a linha correspondente no nosso banco.

Optamos por derivar a governante inteiramente do nosso banco: `listed_at` ASC — o sinal mais
próximo de "há quanto tempo a key está na fila" depois que ela já foi listada — com `id` ASC
como desempate. O desempate é necessário, não cosmético: `listed_at` é uma coluna `date` (sem
hora), então todo lote confirmado numa mesma rodada do `AutoSellUseCase` empata na mesma data.

## Consequences

Se a Gamivo um dia expuser ordenação garantida de keys numa oferta, esta decisão pode ser
revisitada — hoje não há como validar se a ordem real de venda diverge da suposição interna,
e essa mesma limitação já era aceita pelo `AutoSellUseCase` (ADR 0002).
