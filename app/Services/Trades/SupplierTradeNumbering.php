<?php

namespace App\Services\Trades;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * O número da trade por supplier: a primeira trade do supplier é 1, a próxima 2.
 *
 * Sai de um contador no supplier (`suppliers.last_trade_number`), nunca do
 * maior número das trades: o contador só sobe, então número dado não volta —
 * nem quando a trade é apagada, importada ou muda de supplier.
 *
 * O registro do supplier é travado enquanto o contador sobe: duas trades
 * criadas ao mesmo tempo para o mesmo supplier ficam em fila em vez de pegar o
 * mesmo número. Por isso só pode ser chamado dentro da transação que grava a
 * trade — fora dela a trava acabaria antes da gravação.
 */
final class SupplierTradeNumbering
{
    /** Coluna do número em `trades`. */
    public const COLUMN = 'supplier_trade_number';

    /**
     * Modo da trava do supplier no Postgres. **Não** `FOR UPDATE` (o
     * `lockForUpdate()` do Laravel): quem cria a trade já inseriu a linha, e a
     * chave estrangeira segura o supplier em `FOR KEY SHARE`, incompatível com
     * `FOR UPDATE`. Duas criações para o mesmo supplier esperavam uma pela
     * outra — deadlock, e o Postgres abortava uma delas. `FOR NO KEY UPDATE`
     * convive com o `FOR KEY SHARE` e ainda põe duas reservas em fila.
     * *(Reproduzido em duas sessões do Postgres ao revisar, 2026-11-15.)* O
     * SQLite dos testes ignora o modo; a guarda compila esta consulta para
     * Postgres.
     */
    public const LOCK_MODE = 'for no key update';

    /**
     * O número de uma trade daquele supplier, já reservado; nulo sem supplier.
     *
     * @return array{supplier_trade_number: int|null} para `forceFill` na trade
     *
     * @throws \LogicException fora de uma transação
     */
    public function for(?int $supplierId): array
    {
        return [self::COLUMN => $supplierId === null ? null : $this->next($supplierId)];
    }

    /**
     * A leitura do contador, com a trava. Pela tabela, e não pelo model: o
     * supplier soft-deletado ainda pode ter trade criada por um caminho antigo,
     * e o contador dele continua valendo.
     */
    public function counterQuery(int $supplierId): Builder
    {
        return DB::table('suppliers')->where('id', $supplierId)->lock(self::LOCK_MODE);
    }

    private function next(int $supplierId): int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('The supplier trade number must be reserved inside the transaction that records the trade.');
        }

        $next = (int) $this->counterQuery($supplierId)->value('last_trade_number') + 1;

        // Direto na tabela: subir o contador não é uma edição do supplier, e
        // não deve mexer no `updated_at` dele.
        DB::table('suppliers')->where('id', $supplierId)->update(['last_trade_number' => $next]);

        return $next;
    }
}
