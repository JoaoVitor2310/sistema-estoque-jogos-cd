# Sem concorrente, o preço vem do mercado — e o teto não é persistido

**Status:** accepted — implementado em 2026-08-21; refinado no mesmo dia para manter o `max_api` como limite superior (ver "O papel do `max_api`").

O `max_api` é **folga para valorização**, não um preço-alvo: ele só é seguro porque quem freia o preço de verdade é o concorrente contra quem o `ComparisonAlgorithm` mira. Quando não existe concorrente utilizável, esse freio some e a folga vira o preço praticado — uma key de €1 num jogo pesquisado a €20 carrega um `max_api` de €160, e era esse o valor anunciado. A oferta em que somos o único vendedor passa a ser precificada a partir do `market_price` da key governante, por `MinMaxPriceCalculator::soleSellerPrice()`, **calculado na hora da decisão** pelos dois pontos que já têm as offers em mãos (`AutoSellUseCase` e `UpdateOffersUseCase`) e nunca gravado no banco.

Persistir esse teto foi rejeitado. Ausência de concorrência é **situação do produto**: muda a cada minuto e não é propriedade da key. Gravá-la no `max_api` faria a coluna significar duas coisas diferentes dependendo de um estado invisível, e exigiria um desfazer quando o concorrente voltasse — com o teto original já sobrescrito e sem momento confiável para restaurá-lo. Deixando a coluna intacta, a folga de valorização volta sozinha na primeira passada que enxergar concorrente, sem chamada extra ao marketplace para detectar a concorrência e sem scheduler novo.

São dois regimes **condicionais**, não uma composição: com concorrente, o `max_api` continua valendo integralmente, para que o preço acompanhe a valorização real do jogo.

## O papel do `max_api`

O que o regime sem concorrente troca é o **papel** do `max_api`, não a validade dele: ele deixa de ser âncora e continua como limite superior, de modo que o teto efetivo é `min(market_price × 1.10, max_api)`. A primeira versão o ignorava por completo nesse ramo, o que era invisível enquanto a fórmula do import o deixava sempre bem acima do mercado. Deixou de ser invisível quando os limites viraram editáveis na tela de Keys: um `max_api` digitado à mão significa "não passe disso", e ignorá-lo faria a tela prometer um limite que a precificação não honra. A trava de key velha produz o mesmo efeito sem intervenção humana.

Aplicar o limite só pode **baixar** o preço, nunca reinflá-lo — o bug de origem era um `max_api` alto demais (€160 para um jogo de €20), e nesse caso ele não binda.

## Considered Options

- **Calcular na decisão, sem persistir** — escolhido. O sinal viaja no `ComparisonResult` (`REASON_SOLE_SELLER`, preservando o `offerId`), e os dois callers já sabem a lista de ofertas do produto.
- **Gravar o teto de vendedor único no `max_api`** — rejeitado. Sobrescreve um dado permanente com um estado transitório e não tem volta: quando o concorrente reaparece, o teto de valorização original já se perdeu.
- **Um `RegulateMaxApiUseCase` recalculando a coluna num scheduler próprio** — foi a proposta inicial. Rejeitado junto com a persistência: sem coluna para regular, o UseCase não tem trabalho. Também custaria uma varredura de produtos na API só para descobrir o que os dois fluxos já sabem de graça.
- **Limitar sempre o preço a `market_price × 1,10`, independente de concorrência** — rejeitado por destruir o caso que originou o `max_api`. Um teto permanente ancorado numa foto de preço impede o preço de subir quando o jogo **de fato** valoriza, que é exatamente o que a folga existe para permitir.

## Consequences

- **A razão do preço não é visível no sistema.** A tela de Keys mostra Min. API e Max. API, nunca o preço anunciado — que só existe no painel do marketplace. Uma oferta a €10 sob um `max_api` de €24 parece errada até o leitor conhecer esta regra, e **é o esperado, não um clamp quebrado**. O único registro de qual regime precificou cada oferta é o canal de log `schedulers`, na chave `pricing` (`competitor` ou `sole_seller`). Exibir o estado na interface — um marcador de observação persistido e um selo ao lado do teto — foi pesado contra isso e deliberadamente adiado; está registrado em [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md).
- **A precificação passa a ler um campo de contabilidade de compra.** `market_price` é, por definição, o preço pesquisado no dia da trade — base do rateio de `individual_cost`, de `simulated_income` e de `purchase_profit` —, e por isso **não** é atualizado (ver [`0004`](0004-recalculate-trade-on-key-edit.md)). Usá-lo como âncora significa aceitar precificar por uma referência de compra: sem concorrente, uma key parada num jogo que valorizou fica anunciada por um preço velho. Ter preço corrente exigiria coluna própria, não refresh deste campo — registrado em [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md).
- **O piso continua vencendo o teto.** `soleSellerPrice()` reusa o mesmo `clamp` do fluxo normal, então `min_api` acima do teto de mercado resolve o conflito sem tratamento próprio — sem concorrente ninguém nos corta, e não há motivo para furar a margem.
- **A trava de key velha é a única marca permanente.** O passo final do `AutoSellUseCase` trava o `max_api` de keys com ≥ `OLD_KEY_MONTHS` meses no preço de listagem. Se essa listagem aconteceu sem concorrente, o valor gravado é o teto de mercado, e ele **não** é recuperado quando o concorrente volta.
- **O caminho sem concorrente não reenvia preço igual.** Ali o alvo é constante, e a passada roda a cada minuto: `priceAsSoleSeller` lê a oferta atual e só manda o `PUT` se o `seller_price` divergir. O caminho com concorrente ficou sem essa guarda — registrado em [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md).
