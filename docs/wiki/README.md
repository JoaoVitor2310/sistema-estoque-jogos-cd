# Wiki — Sistema Estoque Jogos CD

Porta de entrada do sistema: domínio, fluxos de negócio e automações, organizados em tabelas. Esta wiki **não substitui** a documentação de referência — ela aponta para lá sempre que o detalhe técnico exato importa. Se uma tabela aqui e o texto de referência divergirem, o texto de referência (e o código) vencem; abra uma correção nos dois.

**Convenção destas páginas:** informação em **tabelas**, ordenadas do caso mais comum para o mais raro. A primeira linha de cada tabela é o que acontece na maioria das vezes.

## Páginas

| Página | O que cobre |
|---|---|
| [DOMAIN.md](DOMAIN.md) | Entidades do sistema, como se relacionam, ciclo de vida de uma Key |
| [FLOWS.md](FLOWS.md) | Fluxo de compra (prospecção → trade → key) e fluxo de venda (listagem → reprecificação → baixa) |
| [AUTOMATIONS.md](AUTOMATIONS.md) | Todo scheduler do sistema, a árvore de decisão do `min_api` e a lógica de listagem FIFO |

## Documentação de referência (fonte da verdade)

- [`CONTEXT.md`](../../CONTEXT.md) — glossário da linguagem ubíqua do domínio
- [`docs/PRODUCT.md`](../PRODUCT.md) — regras de negócio detalhadas
- [`docs/GAMIVO.md`](../GAMIVO.md) — integração completa com a Gamivo (algoritmos, API, agendamentos)
- [`docs/GG_DEALS.md`](../GG_DEALS.md) — integração com bundles
- [`docs/PRICE_RESEARCHER.md`](../PRICE_RESEARCHER.md) — integração com o buscador de preços
- [`docs/adr/`](../adr/) — decisões arquiteturais registradas
- [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md) — tudo que ainda falta fazer
- [`CLAUDE.md`](../../CLAUDE.md) — convenções de engenharia e papel do Claude neste projeto

## Como manter esta wiki viva

Estas páginas seguem a mesma regra do resto do repositório (ver `CLAUDE.md` → "Manter a documentação viva"): atualizadas no mesmo passo da mudança que as afeta, nunca depois. Uma tabela desatualizada é pior que nenhuma tabela — se um fluxo, tier, cron ou relação mudar, corrija a tabela aqui antes de fechar a tarefa.
