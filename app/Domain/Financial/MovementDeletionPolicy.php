<?php

namespace App\Domain\Financial;

use App\Domain\Enums\FinancialMonthStatus;
use App\Domain\Enums\MovementCategory;

/**
 * Quando um lançamento ainda pode ser apagado.
 *
 * Apagar é a única correção que existe — não há edição (ver docs/adr/0005). Mas
 * ela vale só enquanto o mês está sendo montado, e só para o que o usuário
 * lançou. Três barreiras, da mais forte para a mais específica:
 *
 *  - **Mês fechado é imutável.** Fechado virou histórico, e os saldos dele já
 *    abriram o mês seguinte como movimentos `opening`; apagar uma linha aqui
 *    faria o passado divergir do presente. Corrigir um mês fechado passa por
 *    reabri-lo.
 *  - **Movimento gerado não se apaga na mão.** Quem desfaz a devolução da verba
 *    de TF2 é o `reopen`, em bloco, junto com o resto do fechamento.
 *  - **Saldo de abertura não é lançamento seu.** Ele veio do fechamento anterior
 *    (ou do bootstrap) e é a única memória do dinheiro que atravessou a virada
 *    do mês; apagá-lo sumiria com esse saldo sem nada o repor.
 *
 * A abertura precisa das duas últimas barreiras porque `is_generated` sozinho
 * não a cobre: ela nasce **manual de propósito** (`is_generated = false`), para
 * sobreviver a uma reabertura do próprio mês — o `reopen` apaga os gerados, e um
 * `opening` gerado seria destruído junto, levando o carry-forward com ele. Ou
 * seja, as duas flags respondem perguntas diferentes: `is_generated` diz *quem
 * desfaz*, a categoria diz *de quem é o lançamento*.
 *
 * A quantidade de linhas não entra na regra: quem garante que as pernas de uma
 * transferência sumam juntas é o `group_id`, não esta policy.
 */
final class MovementDeletionPolicy
{
    public static function guard(FinancialMonthStatus $status, bool $isGenerated, MovementCategory $category): void
    {
        if ($status !== FinancialMonthStatus::Draft) {
            throw new \RuntimeException('Only movements of an open financial month can be deleted.');
        }

        if ($isGenerated) {
            throw new \InvalidArgumentException('A generated movement is undone by reopening the month, not by deleting it.');
        }

        if ($category === MovementCategory::Opening) {
            throw new \InvalidArgumentException('An opening balance carries the previous month forward and cannot be deleted.');
        }
    }
}
