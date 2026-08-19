<?php

use App\Domain\Trades\DeliveryCredential;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A entrega da trade pelo próprio supplier (ver docs/adr/0008).
 *
 * Quatro colunas, e o estado da entrega é derivado delas — não há coluna de
 * status: sem `delivered_at` a trade está em negociação; com ele e sem
 * `is_imported` está na fila de conferência.
 *
 * Toda trade nasce com credencial, então a migração também preenche as que já
 * existiam: sem isso, as trades anteriores seriam as únicas sem link, e a aba
 * precisaria de um caminho separado só para elas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            // Endereço da entrega, não segredo: é UUIDv4 para não ser
            // enumerável nem revelar o volume de trades, como o id sequencial
            // revelaria. Único porque é por ele que a rota pública resolve a
            // trade. Estável pela vida da trade — reemitir rotaciona só o token.
            $table->uuid('delivery_uuid')->nullable()->unique();

            // Encriptado pelo cast do model, não em hash: o código fica à vista
            // na aba de Trades, para a equipe copiar quando quiser, e isso exige
            // poder lê-lo de volta. `text` porque o payload encriptado é bem
            // maior que o token.
            $table->text('delivery_token')->nullable();

            // Gravado só pelo botão explícito de entregar, e guarda o primeiro
            // clique: a página segue editável até o import, então saves
            // posteriores não o alteram.
            $table->timestamp('delivered_at')->nullable();

            // Observação livre do supplier. É o canal para o caso irregular que
            // o resto do desenho fecha de propósito — jogo dado de brinde, jogo
            // que ele não tem mais, ressalva de região —, já que ele não pode
            // criar nem apagar linha.
            $table->text('supplier_notes')->nullable();
        });

        $this->backfillCredentials();
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn(['delivery_uuid', 'delivery_token', 'delivered_at', 'supplier_notes']);
        });
    }

    /**
     * Credencial para as trades que já existiam.
     *
     * Uma a uma, e não um `update` em massa, porque cada trade precisa do seu
     * próprio par: um token compartilhado daria a qualquer supplier acesso à
     * entrega de todos os outros.
     *
     * `chunkById`, não `chunk`: o próprio update tira a linha do `whereNull`, e
     * paginar por offset sobre um filtro que encolhe pula um lote a cada passo.
     */
    private function backfillCredentials(): void
    {
        DB::table('trades')
            ->select('id')
            ->whereNull('delivery_uuid')
            ->chunkById(200, function ($trades) {
                foreach ($trades as $trade) {
                    DB::table('trades')->where('id', $trade->id)->update([
                        'delivery_uuid' => Str::uuid()->toString(),
                        'delivery_token' => Crypt::encryptString(DeliveryCredential::generate()),
                    ]);
                }
            });
    }
};
