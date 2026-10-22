<?php

namespace App\Domain\Trades;

/**
 * O `gamivo_id` de uma linha de trade é derivado do par (`game_name`, `region`)
 * — ver [[LineGamePair]].
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
 *
 * **A key é a exceção: ela recalcula.** Não há import depois dela, então quem
 * edita uma key refaz o id pelo par novo na hora (`UpdateKeyUseCase`) — e, se a
 * key já chegou ao marketplace, a troca pede revisão ([[changeNeedsReview]]),
 * porque ela pode continuar à venda na oferta do id antigo.
 */
final class GamivoIdentity
{
    /** A coluna que deixa de valer quando o par muda. */
    public const DERIVED_COLUMN = 'gamivo_id';

    /** As colunas que a regra precisa ler antes de gravar: o par e o id derivado dele. */
    public const IDENTITY_COLUMNS = [...LineGamePair::COLUMNS, self::DERIVED_COLUMN];

    /** Aviso devolvido quando o id de uma key que já chegou ao marketplace mudou com o par. */
    public const REVIEW_MESSAGE = 'Jogo atualizado, mas o ID Gamivo mudou junto com o nome/região e a key já foi listada ou vendida — confira onde ela está à venda.';

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
            && ! LineGamePair::same($stored[self::DERIVED_COLUMN] ?? null, $patch[self::DERIVED_COLUMN])) {
            return false;
        }

        return LineGamePair::changedBy($stored, $patch);
    }

    /**
     * O id guardado deixou de valer para quem **refaz** o id em vez de apagá-lo?
     *
     * Igual a [[invalidatedBy]], mas um id vazio no payload não conta como id
     * redefinido de propósito: é o formulário que reenvia o campo em branco, e
     * só um id **diferente** do guardado ganha da troca de par.
     *
     * @param  array<string, mixed>  $stored  valores atuais
     * @param  array<string, mixed>  $patch  colunas que a escrita traz
     */
    public static function staleAfter(array $stored, array $patch): bool
    {
        if (empty($patch[self::DERIVED_COLUMN])) {
            unset($patch[self::DERIVED_COLUMN]);
        }

        return self::invalidatedBy($stored, $patch);
    }

    /**
     * A troca de par mudou o id de uma key que já chegou ao marketplace?
     *
     * Key listada ou vendida pode estar à venda na oferta do id antigo, e trocar o
     * id não a tira de lá — quem edita precisa conferir onde ela está vendendo. Se
     * o id resultante é o mesmo, nada mudou para o marketplace e não há o que revisar.
     *
     * @param  array<string, mixed>  $stored  valores atuais
     * @param  array<string, mixed>  $patch  colunas que a escrita traz
     * @param  mixed  $resultingId  o id que vai ficar gravado
     * @param  bool  $reachedMarketplace  a key já foi listada ou vendida
     */
    public static function changeNeedsReview(array $stored, array $patch, mixed $resultingId, bool $reachedMarketplace): bool
    {
        return $reachedMarketplace
            && LineGamePair::changedBy($stored, $patch)
            && ! LineGamePair::same($stored[self::DERIVED_COLUMN] ?? null, $resultingId);
    }
}
