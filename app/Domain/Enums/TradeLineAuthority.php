<?php

namespace App\Domain\Enums;

/**
 * Quem está escrevendo numa linha de trade, e até onde ele alcança.
 *
 * A equipe e o supplier gravam pelo **mesmo** caminho
 * ([[App\UseCases\Trades\UpdateTradeLineUseCase]]); o que muda entre eles é só
 * esta lista. Ver docs/adr/0008.
 *
 * O escopo mora aqui, e não nas regras do Form Request, de propósito: se a
 * barreira fosse a validação, ampliar o alcance do supplier seria acrescentar
 * uma regra — algo que ninguém lê como decisão de segurança. Aqui, ampliar exige
 * editar uma classe de Domain que existe só para isso.
 */
enum TradeLineAuthority: string
{
    case Team = 'team';

    case Supplier = 'supplier';

    /**
     * Colunas de `trade_lines` que esta autoridade pode gravar.
     *
     * `market_price`, `popularity` e `gamivo_id` ficam fora do supplier porque
     * são a saída do `price_researcher` — quanto o jogo dele vale para nós —, e
     * `game_name` porque o conjunto de linhas é da equipe: ele preenche o que
     * existe, não redefine o que foi negociado.
     *
     * `bundle` é a exceção entre os campos pesquisados (2026-08-19): ele chega
     * pré-preenchido pela nossa busca, mas quem teve a key na mão sabe melhor
     * de onde ela veio — e é a origem que costuma explicar o region lock. O que
     * ele sobrescreve aqui é o palpite do lookup, não um número de precificação.
     *
     * @return list<string>
     */
    public function writableColumns(): array
    {
        return match ($this) {
            self::Team => [
                'game_name', 'market_price', 'popularity', 'region',
                'bundle', 'expires_at', 'key_code', 'gamivo_id',
            ],
            self::Supplier => ['region', 'bundle', 'expires_at', 'key_code'],
        };
    }

    /**
     * Em que formato esta autoridade lê e escreve a validade de uma linha.
     *
     * A aba da equipe é em português e lê `dd/mm/aaaa`; a página da entrega é
     * em inglês, e o supplier lê `mm/dd/aaaa` — a mesma data escrita nos dois
     * formatos é uma data diferente, então quem escreveu decide como o texto é
     * lido. A coluna é `date`: os dois formatos só existem na borda.
     */
    public function dateFormat(): string
    {
        return match ($this) {
            self::Team => 'd/m/Y',
            self::Supplier => 'm/d/Y',
        };
    }
}
