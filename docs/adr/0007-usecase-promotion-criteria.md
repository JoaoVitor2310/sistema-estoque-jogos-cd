# Escrita orquestrada é UseCase; leitura fica fora, por causa de CQRS

O ADR 0001 diz que "UseCases still orchestrate through Services and Domain", mas nunca
definiu **quando** algo merece ser um UseCase. Sem esse critério a classificação virou gosto
pessoal: três jobs de scheduler viviam como métodos soltos em Services criados só para o
`Schedule::call` ter onde chamar, enquanto `SupplierController::findNewSuppliers` fazia
`Http::post` cru dentro do controller. Uma auditoria de 2026-08-10 mapeou 16 pontos assim.

Fixamos o critério: **um UseCase é uma operação de escrita disparada de fora (HTTP, cron, CLI)
que orquestra passos.** Orquestrar passos é o suficiente — não é preciso contar colaboradores,
nem cruzar domínios. Montar uma URL, autenticar, chamar um serviço externo e traduzir a falha
já é orquestração, ainda que o colaborador seja um só.

**Leitura não vira UseCase**, e essa é a única exceção real. Não é por ser pequena demais: é
porque a leitura vai por um caminho próprio — Controller → Repository/Service, com a whitelist
de filtros declarada num FormRequest na fronteira HTTP. Manter os dois lados separados desde
já é o que torna barata a adoção de **CQRS**, que é a direção pretendida para este sistema.
Uma consulta com dez filtros continua não sendo UseCase, por mais elaborada que fique.

Fora da escrita orquestrada e da leitura, sobra um caso: o **statement único sobre um modelo
só** — `find`, `create`, `update`, `delete` sem ramificação, sem efeito secundário e sem
chamada externa. Não há passo para orquestrar, então ele fica no controller (`FeeController::destroy`)
ou num Service, se já houver um.

## Considered Options

**Efeito + 2 colaboradores** *(critério originalmente adotado, revertido nesta revisão)*.
Contar colaboradores dava uma linha objetiva, mas errava por baixo: `findNewSuppliers` ficava
de fora por ter um colaborador só, e o `Http::post` continuava no controller — exatamente o
vazamento de infraestrutura que a auditoria queria eliminar. Pior, o número virava discussão
em todo caso de borda (um agregado e sua tabela pivot contam como um?). Rejeitado por
transformar uma decisão de camada numa contagem.

**Todo write vira UseCase, sem exceção.** Consistência máxima (controller nunca toca Eloquent),
ao custo de ~25 classes anêmicas do tipo `DeleteFeeUseCase` chamando só `->delete()`. Rejeitado
pela regra de wrappers do próprio `CLAUDE.md` — mas note que o critério adotado fica bem
próximo deste: a exceção é estreita de propósito.

**Só o que cruza 2+ domínios.** Mais restritivo — o alerta de expiração (Key + Mail) não seria
UseCase e o scheduler continuaria chamando Service. Rejeitado porque deixava de pé exatamente
o problema que motivou a auditoria.

**Leitura complexa também vira UseCase.** Simétrico e fácil de explicar, mas apaga a fronteira
que o CQRS precisa: quando o lado de leitura for para outro modelo, teríamos que desfazer
UseCases um a um para separá-los de novo. Rejeitado.

## Consequences

Serão mais classes do que no critério anterior, e algumas com um método de poucas linhas —
`FindNewSuppliersUseCase` é um `Http::post` e a tradução da resposta. É o preço aceito em
troca de uma regra que se aplica sem julgamento caso a caso: se escreve e tem passos, é
UseCase.

A assimetria entre controllers deixa de ser sobre tamanho ou contagem e passa a ser sobre
**natureza da operação**. `FeeController::destroy` fala Eloquent direto por ser um statement
único; `GameController::store` delega porque orquestra passos. Quem for "uniformizar" isso
deve ler este ADR primeiro.

A fronteira que passa a exigir atenção é outra: uma operação que hoje é statement único e
amanhã ganha uma segunda etapa (um log, uma chamada externa, uma validação que consulta outra
tabela) cruzou a linha e deve virar UseCase no mesmo commit — não depois.
