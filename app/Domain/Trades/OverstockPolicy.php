<?php

namespace App\Domain\Trades;

use Carbon\Carbon;

/**
 * Quando um jogo está encalhado no estoque — e a linha da trade que o traz
 * recebe o aviso.
 *
 * **O jogo encalhado não é ofertado, mas é registrado.** Na prospecção ele sai
 * do comentário postado na lista do supplier; na trade ele continua como linha,
 * marcada em `trade_lines.is_overstocked`, para a equipe ver o que ficou de
 * fora da oferta. Ver docs/adr/0013.
 *
 * As três condições valem **juntas**, e cada uma existe para corrigir um erro
 * que as outras duas cometeriam sozinhas:
 *
 *  1. `MIN_STOCK` — uma ou duas keys paradas é operação normal, não acúmulo.
 *  2. `MIN_AGE_DAYS` — metade das keys que vendem leva mais de 24 dias, então
 *     um mês parado ainda é venda normal, não encalhe. E key de bundle é
 *     comprada em quantidade para ser vendida uns 3 meses depois, quando o
 *     preço se recupera do lançamento: estoque parado dentro dessa espera é
 *     estratégia, não acúmulo.
 *  3. `MAX_COVERAGE_DAYS` — jogo que vende rápido aguenta estoque alto. O que
 *     condena não é a quantidade, é o tempo que ela levaria para escoar no
 *     ritmo do próprio jogo.
 *
 * O estado do estoque é derivado a cada consulta, nunca gravado. O que se
 * grava é só a marca da linha, no momento em que ela nasce ou troca de jogo.
 */
final class OverstockPolicy
{
    /** A coluna de `trade_lines` onde a marca de encalhe é gravada. */
    public const FLAG_COLUMN = 'is_overstocked';

    /** Keys paradas a partir das quais o jogo entra em avaliação. */
    public const MIN_STOCK = 3;

    /**
     * Idade (dias) da key parada mais antiga para o estoque contar como encalhado.
     *
     * Quatro meses: a compra de bundle espera ~3 meses de valorização antes de
     * vender, e um limiar dentro dessa espera acusaria como encalhe o lote que
     * está parado de propósito. O mês a mais é a folga para a listagem não sair
     * no dia exato.
     */
    public const MIN_AGE_DAYS = 120;

    /** Janela (dias) de vendas que define o ritmo do jogo. */
    public const SALES_WINDOW_DAYS = 90;

    /** Tempo (dias) que o estoque pode levar para escoar antes de o jogo ser sinalizado. */
    public const MAX_COVERAGE_DAYS = 120;

    /**
     * Em quantos dias o estoque atual escoaria no ritmo da janela.
     *
     * Sem arredondar: é este valor que [[self::isOverstocked]] compara com
     * `MAX_COVERAGE_DAYS`, e arredondar antes deixaria 120,4 dias passar como
     * 120 — dentro do limite que diz "mais de 120".
     *
     * `null` quando não houve venda nenhuma: sem ritmo não há previsão, e o
     * caso é tratado como pior que qualquer prazo — ver [[self::isOverstocked]].
     */
    public static function coverageDays(GameStock $game): ?float
    {
        if ($game->soldInWindow <= 0) {
            return null;
        }

        return $game->stock * self::SALES_WINDOW_DAYS / $game->soldInWindow;
    }

    /**
     * O primeiro dia que ainda conta na janela de vendas.
     *
     * `keys.sold_at` é data, sem hora, então a janela é de dias inteiros e
     * inclui hoje: 90 dias são hoje e os 89 anteriores. Recuar os 90 dias
     * inteiros contaria 91.
     */
    public static function salesWindowStart(Carbon $now): Carbon
    {
        return $now->copy()->startOfDay()->subDays(self::SALES_WINDOW_DAYS - 1);
    }

    public static function isOverstocked(GameStock $game, ?Carbon $now = null): bool
    {
        if ($game->stock < self::MIN_STOCK) {
            return false;
        }

        $now ??= Carbon::now();

        // Estoque sem data de aquisição não tem idade que se possa provar, e a
        // regra só sinaliza o que ela consegue justificar.
        if ($game->oldestAcquiredAt === null) {
            return false;
        }

        // `MIN_AGE_DAYS` é inclusivo: a key parada há exatamente esse tempo já conta.
        if ($game->oldestAcquiredAt->greaterThan($now->copy()->subDays(self::MIN_AGE_DAYS))) {
            return false;
        }

        $coverage = self::coverageDays($game);

        return $coverage === null || $coverage > self::MAX_COVERAGE_DAYS;
    }
}
