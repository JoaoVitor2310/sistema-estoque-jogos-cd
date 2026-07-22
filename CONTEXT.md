# Sistema Estoque Jogos CD

Inventário e automação para trading de keys de jogos digitais — do registro da compra até a venda automática e a reprecificação competitiva no marketplace Gamivo.

## Language

### Keys e precificação

**Key**:
Uma chave de jogo digital comprada e/ou vendida — a unidade central de estoque em torno da qual gravitam preço, listagem e trade.
_Avoid_: licença, item, código (isoladamente).

**Governante**:
Entre as keys aprovadas para entrar na mesma oferta (compartilham o mesmo produto no marketplace), a mais antiga — define o preço único da oferta, pois o marketplace vende por ordem de chegada (FIFO).
_Avoid_: key primária, key líder.

**min_api / max_api**:
Os limites inferior e superior dentro dos quais o motor de reprecificação automática do marketplace pode mover o preço de uma key.
_Avoid_: piso / teto isoladamente (min_api/max_api são os termos usados no dia a dia).

**Key velha**:
Uma key comprada há muito tempo sem vender — seu piso de preço é permanentemente rebaixado (não volta a subir mesmo que a key seja listada depois) e, uma vez listada, seu teto fica travado no preço praticado. A key parada é dinheiro parado: melhor vender mais barato e reinvestir o capital em novas compras do que segurar o estoque esperando um preço melhor que pode nunca vir.
_Avoid_: key parada, key expirada (conceito diferente — ver Key expirando).

**Limbo**:
Uma key listada há muito tempo sem vender — recebe o mesmo piso permanente que a key velha, e pela mesma razão (capital parado), mas contado a partir da data de listagem, não da compra.
_Avoid_: key travada.

**Key expirando**:
Uma key cuja data de expiração está próxima — piso de preço rebaixado incondicionalmente, independente de custo ou idade, para forçar a venda antes de perder o valor.
_Avoid_: key expirada (já passou do prazo — conceito distinto, não coberto hoje).

**Gift link**:
Uma key cujo código é uma URL de resgate, não um código de ativação direto — nunca elegível para listagem automática.
_Avoid_: key de link.

### Bundles

**Bundle**:
Um conjunto de jogos vendidos juntos por um preço único. Quando um jogo é lançado dentro de um bundle, seu preço de mercado despenca; ele volta a subir em até ~4 meses. É exatamente essa queda inicial que torna o lançamento de um bundle uma boa oportunidade de **compra** — o jogo fica temporariamente barato antes do preço se recuperar.
_Avoid_: pacote, coleção.

**Janela de exclusão do bundle**:
Período após o lançamento de um bundle durante o qual keys dos jogos daquele bundle não são listadas automaticamente para **venda** — o preço ainda está em queda livre, então vender agora significaria vender na baixa. Não impede a compra, só a venda.
_Avoid_: cooldown, período de graça.

**Choice**:
Tipo de bundle (Humble Choice) identificado pelo título — tratado separadamente do bundle comum.
_Avoid_: bundle tipo 2.

### Precificação competitiva

**Price dumper**:
Um concorrente cujo preço listado está anomalamente abaixo do segundo colocado — é ignorado ao calcular o preço-alvo, para não perseguir um preço irreal.
_Avoid_: lowballer, concorrente barato.

**Concorrente com bot**:
Um vendedor conhecido por rodar precificação automática própria — quando ficamos em 2º atrás dele, o sistema mira no 3º colocado em vez de entrar numa guerra de preços com o bot.
_Avoid_: bot, vendedor automático.

**Wholesale**:
Modo de venda no atacado — o comprador leva mais de 10 unidades de uma vez, pagando menos por jogo, e o marketplace cobra uma taxa fixa menor sobre a venda do que cobraria no modo retail padrão.
_Avoid_: modo atacado (isolado, sem explicar o mecanismo), bulk.

### Suppliers e Trades

**Supplier**:
Um perfil Steam de onde keys são obtidas via troca — rastreado independentemente de já ter havido troca (`has_traded`) ou de ter sido curado manualmente (`is_added`).
_Avoid_: fornecedor (termo antigo do banco), vendor.

**Trade**:
Uma lista de jogos comentada/ofertada a um supplier em um momento específico.
_Avoid_: vip list, lista (isoladamente).

**Prospecção**:
Avaliar a lucratividade dos jogos oferecidos por um supplier e decidir se vale comentar (de novo) naquela lista.
_Avoid_: scouting.

**Trade em estoque**:
Uma trade é considerada "em estoque" quando pelo menos um dos `key_code` ofertados já está presente em alguma Key do estoque — ou seja, já compramos aquele jogo daquele lote.
_Avoid_: trade concluída, trade fulfilled.
