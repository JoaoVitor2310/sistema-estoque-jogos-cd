<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada oferta feita a um supplier numa trade — uma linha por rodada.
 *
 * Registro, não estado: a oferta enviada é um fato com a cotação daquele
 * momento, e uma nova rodada cria outra linha em vez de reescrever esta. A
 * vigente é a de rodada mais alta. Ver docs/adr/0016.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_offers', function (Blueprint $table) {
            $table->id();

            // A oferta não existe fora da trade. Trade é soft-deletada (ADR 0011),
            // então o cascade só age numa exclusão definitiva.
            $table->foreignId('trade_id')->constrained('trades')->cascadeOnDelete();

            // 1 é a primeira oferta; a última permitida é a final
            // (`SupplierOffer::MAX_ROUNDS`). Única por trade: um callback repetido
            // não grava duas primeiras ofertas.
            $table->unsignedTinyInteger('round');

            // Lucro efetivo: o da categoria na primeira oferta, o que resultar do
            // total aceito numa renegociação — por isso decimal.
            $table->decimal('profit_percent', 6, 2);

            // Moeda da oferta inteira — todas as linhas estão nela.
            $table->string('currency', 8);

            // Quanto vale 1 TF2 key na moeda da oferta, no momento (1 em TF2). É o
            // único dado da oferta que não se recalcula depois — o sistema só
            // guarda a cotação atual —, e é o que explica o valor de cada jogo.
            $table->decimal('tf2_rate', 12, 4);

            // O que o supplier pediu para provocar esta rodada; nulo na primeira.
            $table->decimal('requested_total', 12, 2)->nullable();

            // Sem total nem "final": os dois se calculam (a soma das linhas e a
            // rodada). Imutável: só `created_at`.
            $table->timestamp('created_at')->nullable();

            $table->unique(['trade_id', 'round']);
        });

        Schema::create('trade_offer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_offer_id')->constrained('trade_offers')->cascadeOnDelete();

            // Para casar com a linha da trade enquanto ela existir. Nulo se a
            // equipe apagar a linha: a oferta já feita continua de pé.
            $table->foreignId('trade_line_id')->nullable()->constrained('trade_lines')->nullOnDelete();

            // Cópia do que o supplier leu: a linha da trade muda depois (ele edita
            // a região na página da entrega), e a oferta tem de continuar
            // dizendo o que foi oferecido.
            $table->string('game_name');
            $table->string('region')->nullable();

            // O que o supplier leu por este jogo, na moeda da oferta. A oferta
            // exata e o limite do min_api só importam durante o cálculo: a
            // rodada seguinte parte do valor lido, com a cotação do momento.
            $table->decimal('price', 12, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_offer_lines');
        Schema::dropIfExists('trade_offers');
    }
};
