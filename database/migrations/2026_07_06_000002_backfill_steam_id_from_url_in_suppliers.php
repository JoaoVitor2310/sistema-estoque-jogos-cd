<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // REGEXP_REPLACE é exclusivo do PostgreSQL — SQLite (testes) não tem dados reais para migrar.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // DISTINCT ON garante um único candidato por steam_id extraído (menor id vence).
        // NOT EXISTS exclui steam_ids que já existem em outro registro.
        DB::statement("
            UPDATE suppliers
            SET steam_id = extracted.new_steam_id
            FROM (
                SELECT DISTINCT ON (new_steam_id) id, new_steam_id
                FROM (
                    SELECT id, REGEXP_REPLACE(RTRIM(url, '/'), '.*/profiles/', '') AS new_steam_id
                    FROM suppliers
                    WHERE steam_id IS NULL
                      AND url IS NOT NULL
                      AND url LIKE '%/profiles/%'
                ) AS candidates
                WHERE NOT EXISTS (
                    SELECT 1 FROM suppliers s2 WHERE s2.steam_id = candidates.new_steam_id
                )
                ORDER BY new_steam_id, id
            ) AS extracted
            WHERE suppliers.id = extracted.id
        ");
    }

    public function down(): void
    {
        // Irreversível com segurança — não sabemos quais steam_ids vieram desta migrate
        // vs quais já existiam antes
    }
};
