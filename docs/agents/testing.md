# Testes

Testes são obrigatórios — nunca entregar uma implementação sem os testes correspondentes no mesmo passo. O projeto usa três camadas de teste, cada uma com responsabilidade distinta:

| Camada | Localização | O que testa | Exemplo |
|--------|-------------|-------------|---------|
| **Unit** | `tests/Unit/Domain/` | Lógica pura de Domain — sem banco, sem framework, sem `app()` | `TradeGameComparison::hasChanged()` com arrays literais |
| **Integration** | `tests/Feature/UseCases/` | Orquestração de UseCases — chama `app(UseCase::class)->execute()` direto, com banco real | `ProspectSupplierUseCase` retorna `last_commented_at` e `games_changed` corretos |
| **Feature** | `tests/Feature/` | HTTP ponta a ponta — auth, validação, persistência, estrutura da resposta | `POST /suppliers/prospect` retorna 401 sem token |

## Regras de distribuição

- Lógica de comparação, cálculo ou decisão que vive no Domain → Unit test
- Comportamento do UseCase (o que orquestra, o que persiste, o que retorna) → Integration test via `app()`
- Contratos HTTP (status codes, campos da resposta, middleware) → Feature test via HTTP
- **O teste mora no arquivo da unidade que ele exercita** — *o teste tem que estar onde quem mexe no código vai rodar*. Nada de arquivo temático juntando várias unidades: se três UseCases gravam o mesmo campo, cada um asserta o próprio. A asserção repetida custa três linhas; o sinal perdido custa um bug em produção. Arquivo temático só se sustenta para o que não pertence a nenhuma unidade — a forma como uma coluna é guardada, um contrato entre camadas. *(Já aconteceu: `Every trade is born with a delivery credential` centralizava a asserção dos três criadores de trade; `ProspectSupplierUseCase` ficou de fora sem ninguém notar, e quem editasse `CreateTradeUseCase` veria `CreateTradeUseCaseTest` verde com a credencial quebrada.)*
- Não duplicar **entre camadas**: se a lógica já está coberta no Unit, o Feature test não precisa repetir todos os casos — só o caminho feliz e o erro principal. Isso não contradiz a regra acima: lá a repetição é *entre unidades da mesma camada*, e cada cópia guarda um caminho de código diferente
- Padrão: Pest. Use `DB::table()` para seeds, nunca Factories quando o dado é simples

## Armadilhas conhecidas

- **Cuidado com asserções variádicas do Pest.** `toContain()` aceita vários needles, então `expect($x)->not->toContain('', 'minha mensagem')` trata a mensagem como um segundo needle e a asserção afrouxa em silêncio — passa mesmo quando `''` está presente. Quando precisar de mensagem, use o método do PHPUnit (`$this->assertNotContains($needle, $haystack, $message)`). *(Já aconteceu: uma guarda de regressão nasceu verde e inútil.)*
- **Teste novo tem que falhar sem a correção.** Antes de fechar, reverta a implementação e confirme o vermelho. Guarda que passa nos dois estados não guarda nada.
- **Produção é Postgres, teste é SQLite — a diferença esconde bugs.** O SQLite não tem tipagem de coluna e aceita calado o que o Postgres rejeita: `data != ''` estoura `invalid input syntax for type date`, e `ILIKE` não existe fora do Postgres. Escreva SQL que roda nos dois (`LOWER(col) LIKE ?` em vez de `ILIKE`; só compare com `''` coluna de texto) e, quando a diferença não puder ser exercitada pela suíte, teste o **SQL gerado** — capture com `DB::listen()` e asserte sobre `sql`/`bindings`. *(Já aconteceu duas vezes: `ILIKE` deixou `KeyController::search` sem nenhum teste possível, e comparar `listed_at` com `''` derrubou a busca em produção com 500.)*
- **Variável do `phpunit.xml` não vence variável do container.** O `<env force="true">` do PHPUnit escreve em `$_ENV`/`putenv`, mas o `ServerConstAdapter` do Laravel lê **`$_SERVER`** primeiro — e o compose exporta `APP_ENV=local` no container (fallback de ambiente; desde 2026-08-24 quem decide entre `npm run dev` e `npm run build` é o `.env`, via `docker/resolve-app-env.sh`). Variável que o ambiente do container também define precisa de `<server name="...">` ao lado do `<env>`. Hoje `APP_ENV` é a única nessa situação, e `tests/Feature/EnvironmentTest.php` guarda isso. *(Já aconteceu: a suíte rodava como `local`, `ValidateCsrfToken::runningUnitTests()` devolvia falso e 23 testes de trade delivery falhavam com 419 — sintoma longe da causa.)*

## Comandos

```bash
php artisan test --no-coverage   # suíte completa (Pest)
./vendor/bin/pest <arquivo>      # arquivo isolado
```
