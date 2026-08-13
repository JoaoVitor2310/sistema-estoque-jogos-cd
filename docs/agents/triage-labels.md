# Triage Labels

As skills falam em termos de cinco papéis canônicos de triagem. Este repositório não usa labels de um tracker externo — os papéis viram o valor de uma linha `**Status:**` dentro da própria entrada em [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md) (ver [`issue-tracker.md`](issue-tracker.md#triage-state)).

| Papel em mattpocock/skills | Valor de `Status:` neste repo | Significado |
| --- | --- | --- |
| `needs-triage` | `needs-triage` | Alguém do time precisa avaliar o item |
| `needs-info` | `needs-info` | Falta informação de quem reportou |
| `ready-for-agent` | `ready-for-agent` | Totalmente especificado, um agente AFK pode pegar |
| `ready-for-human` | `ready-for-human` | Exige implementação humana |
| `wontfix` | *(sem valor — a entrada é removida)* | Não será feito |

Quando uma skill menciona um papel (ex: "aplique o label de pronto para agente"), use o valor correspondente na linha `**Status:**` da entrada. `wontfix` é o único papel que não persiste como Status — ele remove a seção inteira de `docs/IMPROVEMENTS.md`.

Uma entrada sem linha `Status:` é tratada como `ready-for-human` por padrão (ver `issue-tracker.md`).
