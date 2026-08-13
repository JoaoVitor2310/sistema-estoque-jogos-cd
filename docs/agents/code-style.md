# Code style (Pint — preset Laravel)

O projeto usa o Pint sem `pint.json`, portanto aplica o preset `laravel` padrão. Todo código gerado deve já respeitar essas regras para não gerar diff desnecessário no `pint --fix`.

## Espaçamento e indentação

- 4 espaços (sem tabs)
- Sem trailing whitespace; arquivo termina com `\n`
- Linha em branco após `namespace` e após o bloco de `use`
- Linha em branco antes de `return` quando há código acima — exceto quando o corpo do método tem só uma linha

## Chaves e quebras de linha

- Chave de abertura de classe e método na **mesma linha** da assinatura (K&R style): `function foo(): void {`
- `if`, `foreach`, `while` sempre com chaves, mesmo para uma linha
- Chave de fechamento de classe/método em linha própria

## Arrays

- Nunca alinhar `=>` com espaços extras — espaçamento simples: `'key' => $value`
- Arrays curtos (inline) sem espaço após `[` e antes de `]`: `['a', 'b']`
- Arrays multilinha: cada item em sua própria linha, vírgula trailing na última entrada

## Tipos e declarações

- `declare(strict_types=1)` **não** é usado neste projeto (preset Laravel não o exige)
- Tipos nativos sempre que possível (`int`, `string`, `float`, `bool`, `array`, `?Type`)
- `return type` obrigatório em todos os métodos
- Propriedades de classe sempre tipadas

## Imports

- Um `use` por linha, sem grupos
- Ordenados alfabeticamente dentro de cada bloco (classes, functions, constants)
- Sem `use` não utilizado

## Visibilidade e modificadores

- Sempre declarar visibilidade (`public`, `protected`, `private`) em propriedades e métodos
- Ordem dos modificadores: `final`/`abstract` → visibilidade → `static` → nome

## Strings

- Aspas simples por padrão; aspas duplas só quando há interpolação ou caractere especial que exija

## Operadores

- Espaço antes e depois de operadores binários (`===`, `!==`, `+`, `-`, etc.)
- Sem espaço entre operador unário e operando (`!$flag`, `-$value`)

## Comandos

```bash
./vendor/bin/pint --test   # verifica sem corrigir (usado no CI)
./vendor/bin/pint          # corrige
```
