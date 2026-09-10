<?php

namespace App\Domain\Trades;

/**
 * O `gamivo_id` de uma linha de trade é derivado do par (`game_name`, `region`).
 *
 * O mesmo jogo é um produto diferente na Gamivo em cada região, então o id só
 * significa alguma coisa amarrado ao par que o encontrou. Mudou o par, o id
 * guardado passa a endereçar outro produto — e é no meio da negociação com o
 * supplier que essa troca passa despercebida: quem corrige o nome ou a região
 * está quase sempre trocando o jogo tradado, não a grafia dele.
 *
 * **Apagar, e não recalcular aqui.** Linha sem id se conserta sozinha no import
 * — `RegisterKeyUseCase` refaz o lookup pelo par **novo** —, enquanto id errado
 * atravessa a importação calado e ainda é propagado para a tabela `games` por
 * `GameService::fillIdGamivo`. A regra erra para o lado que se corrige.
 */
final class GamivoIdentity
{
    /** As colunas de que o id é derivado. */
    public const IDENTIFYING_COLUMNS = ['game_name', 'region'];

    /** A coluna que deixa de valer quando o par muda. */
    public const DERIVED_COLUMN = 'gamivo_id';

    /**
     * A escrita invalida o id que a linha guarda?
     *
     * `$patch` é parcial: coluna ausente é coluna inalterada.
     *
     * A primeira condição é o que impede a regra de atropelar quem sabe o que
     * está fazendo. Se a mesma escrita traz um id **diferente** do guardado,
     * quem escreve está redefinindo o id de propósito — corrigiu o jogo e já
     * colou o id certo —, e apagá-lo desfaria a correção. Um id igual ao
     * guardado não diz nada: é o valor que a tela reenvia a cada gravação de
     * linha, junto com o campo que ele realmente mexeu.
     *
     * @param  array<string, mixed>  $stored  valores atuais da linha
     * @param  array<string, mixed>  $patch  colunas que a escrita traz
     */
    public static function invalidatedBy(array $stored, array $patch): bool
    {
        if (array_key_exists(self::DERIVED_COLUMN, $patch)
            && ! self::same($stored[self::DERIVED_COLUMN] ?? null, $patch[self::DERIVED_COLUMN])) {
            return false;
        }

        foreach (self::IDENTIFYING_COLUMNS as $column) {
            if (array_key_exists($column, $patch)
                && ! self::same($stored[$column] ?? null, $patch[$column])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Igualdade como o lookup do id a enxerga: `GameService::getIdGamivo` casa o
     * nome por `LOWER(...)`. Retocar a caixa ou um espaço sobrando não é trocar
     * de jogo, e apagar o id a cada retoque desses seria ruído.
     */
    private static function same(mixed $before, mixed $after): bool
    {
        return self::canonical($before) === self::canonical($after);
    }

    private static function canonical(mixed $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', (string) $value) ?? '';

        return mb_strtolower(trim($collapsed));
    }
}
