# O canal de compra mora na trade, não na key

**Status:** accepted — implementado em 2026-09-13, em duas fatias: identificação do canal, e margem inicial do `min_api` por canal (ver "O `min_api` da compra direta").

Parte das keys deixou de vir de supplier: são compradas direto na loja do bundle (Humble, Fanatical, Green Man Gaming…) ou na própria Gamivo. Pagamos mais caro por elas, e a precificação vai precisar tratá-las de outro jogo. Até aqui a única pista era o **nome do bundle digitado no campo de fornecedor**, que o `CreateTradeUseCase`/`UpdateTradeUseCase` transformavam num `Supplier` falso — o mesmo contorno já tinha produzido suppliers como `GAMIVO` e `ativacao pessoal`.

A decisão é gravar o **canal de compra** na trade: `trades.purchase_channel` (enum `PurchaseChannel`: `supplier_trade` | `bundle_store` | `gamivo`) e, para a compra direta, `trades.bundle_id` (FK → `bundles`). A contraparte que o canal não usa é descartada na escrita, e a `ImportReadinessPolicy` cobra a que ele exige. O `bundle_id` não é escolhido num seletor: sai do título da trade por casamento exato com `bundles.name` (`BundleService::findIdByName`), porque a trade criada pela pesquisa de bundle já traz o nome dele.

## O `min_api` da compra direta

A compra direta troca só a **margem inicial** da `MinimumMarginPolicy`: 40% fixa (`BUNDLE_STORE_MARGIN`) no lugar da faixa de custo (40–55%). O decaimento por tempo e os pisos absolutos são os mesmos da trade com fornecedor; a Gamivo segue o fornecedor (compra rara, só para registro). O canal é parâmetro **obrigatório** de `minApi()`/`requiredMargin()` — um chamador que o esquecesse precificaria a compra direta pela faixa de custo sem erro nenhum.

Duas alternativas foram pesadas. Uma **curva de decaimento própria**, mais agressiva depois dos 3–4 meses, foi descartada pelo negócio: a curva de fornecedor já serve. Um **teto** `min(40%, margem)` sobre a curva atual daria o mesmo resultado, mas só enquanto nenhuma margem de tempo passar de 40%; trocar a margem inicial deixa isso explícito numa invariante testada (a compra direta nunca exige mais que o fornecedor na mesma situação), em vez de um `min()` que esconderia a regra.

A janela de exclusão de bundle do auto-sell é de 21 dias (`KeyEligibility::BUNDLE_EXCLUSION_DAYS`), não de meses: o que segura a key de compra direta enquanto o preço se recupera do lançamento é o próprio `min_api`.

## Considered Options

- **Canal explícito na trade + FK de bundle** — escolhido. O canal é fato do lote: todas as keys de uma compra vieram do mesmo lugar, e a `Trade` já é o lote (`keys.trade_id`). Uma coluna de enum aceita canais novos sem migration de schema, e a FK dá integridade ao bundle comprado.
- **Derivar o canal das colunas preenchidas** (`bundle_id` presente = compra direta) — rejeitado. Não comporta a Gamivo, que não tem contraparte nenhuma, nem o próximo canal. E deixaria a pesquisa de bundle ambígua: a trade que ela cria sabe de que bundle os jogos saíram, mas nem sempre vira compra direta — a mesma pesquisa serve para ofertar ao supplier.
- **Flag `is_bundle` ou FK `bundle_id` na key** — rejeitado. Repete em cada key o que é do lote, e colide com `trade_lines.bundle`, que já significa *de que bundle a key saiu* (region lock) — sentido válido também para key de supplier. Uma coluna parecida, `keys.in_bundle`, já foi removida por essa confusão.
- **Continuar pelo nome no campo de fornecedor** — rejeitado. Polui `suppliers`, que deveria guardar só perfis Steam, e o nome ficava como texto solto sem vínculo nenhum com o bundle.
- **Seletor de bundle na aba** — rejeitado. O título da trade vinda da pesquisa **já é** o nome exato do bundle, então escolher de novo numa lista seria trabalho repetido e carregaria todos os bundles na página. O casamento pelo título não é o palpite que se quis evitar: é exato, vira FK gravada, e quando falha a trade fica sem `bundle_id` — o import recusa e a aba avisa, em vez de passar calado.

## Consequences

- **`keys.supplier_id` é legitimamente nulo; `keys.supplier_url` não.** Key de compra direta ou da Gamivo não tem fornecedor, mas `supplier_url` continua `NOT NULL` e passa a guardar a **origem legível** da key: URL do supplier, nome do bundle ou `Gamivo` (`PurchaseChannel::keySource()`). Tornar a coluna nula foi considerado e rejeitado: o risco de uma key de supplier perder a origem numa edição valia mais que o ganho, e o texto preenchido mantém a origem visível e filtrável na tela de Keys em todo canal. O preço é a coluna não guardar mais só URL — e a edição de key precisa saber o canal para não transformar "Gamivo" ou o nome do bundle num `Supplier`.
- **A key conhece o canal pela trade.** `Key::purchaseChannel()` lê `trade.purchase_channel`; key anterior ao vínculo `trade_id` é tratada como trade com fornecedor. Quem lê o canal de muitas keys carrega `trade` antes (o `RegulateMinApiUseCase` e os relatórios de `min_api` fazem isso).
- **Reclassificar uma trade reprecifica as keys dela no dia seguinte.** Não há backfill: a passada das 07:30 do `RegulateMinApiUseCase` relê o canal de toda key não vendida.
- **O histórico não foi reclassificado automaticamente.** Toda trade existente ficou `supplier_trade`, inclusive as que carregam nome de bundle no fornecedor. A reclassificação é manual pela aba: escolher o canal e deixar o título igual ao nome do bundle.
- **O custo segue em TF2 em todos os canais.** O valor em euro é convertido antes de entrar na trade, então o rateio de `individual_cost` não muda.
