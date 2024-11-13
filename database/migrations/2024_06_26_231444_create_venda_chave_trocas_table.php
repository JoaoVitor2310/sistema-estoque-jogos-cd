<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('venda_chave_trocas', function (Blueprint $table) {
            $table->id();
            $table->string('color')->nullable();
            $table->unsignedBigInteger('id_fornecedor')->default(1);
            $table->foreign('id_fornecedor')->references('id')->on('fornecedor');
            
            $table->unsignedBigInteger('tipo_reclamacao_id')->default(1);
            $table->foreign('tipo_reclamacao_id')->references('id')->on('tipo_reclamacao');
            
            $table->string('steamId')->nullable();
            $table->integer('tipo_formato_id')->default(1);
            $table->foreign('tipo_formato_id')->references('id')->on('tipo_formato');
            
            $table->string('chaveRecebida');
            $table->boolean('repetido')->default(false);
            $table->string('plataformaIdentificada')->default('Nenhuma');
            $table->string('nomeJogo');
            $table->decimal('precoJogo', total: 8, places: 2);
            $table->integer('notaMetacritic')->default(0);
            $table->boolean('isSteam')->nullable();
            $table->string('randomClassificationG2A')->nullable();
            $table->string('randomClassificationKinguin')->nullable();
            $table->string('observacao')->nullable();

            $table->integer('id_leilao_g2a')->default(1);
            $table->foreign('id_leilao_g2a')->references('id')->on('tipo_leilao');

            $table->integer('id_leilao_gamivo')->default(1);
            $table->foreign('id_leilao_gamivo')->references('id')->on('tipo_leilao');
            
            $table->integer('id_leilao_kinguin')->default(1);
            $table->foreign('id_leilao_kinguin')->references('id')->on('tipo_leilao');
            
            // $table->string('plataforma')->nullable();

            $table->integer('id_plataforma')->default(1);
            $table->foreign('id_plataforma')->references('id')->on('plataforma');
            
            $table->decimal('precoCliente', total: 8, places: 2)->nullable();
            $table->decimal('precoVenda', total: 8, places: 2)->nullable();
            $table->decimal('incomeReal', total: 8, places: 2)->nullable();
            $table->decimal('incomeSimulado', total: 8, places: 2)->nullable();
            $table->string('chaveEntregue')->nullable(); // Key enviada para troca
            $table->string('valorPagoTotal')->nullable(); // Pode ser o jogo enviado ou o valor pago total
            $table->decimal('qtdTF2', total: 8, places: 2)->nullable();
            $table->decimal('valorPagoIndividual', total: 8, places: 2)->nullable();
            $table->boolean('vendido')->nullable(); // 1 - vendido, 0 - não vendido
            $table->integer('leiloes')->default(0);
            $table->integer('quantidade')->default(0);
            $table->boolean('devolucoes')->nullable();
            $table->decimal('lucroRS', total: 8, places: 2)->default(0);
            $table->decimal('lucroPercentual', total: 8, places: 2)->default(0);
            $table->decimal('valorVendido', total: 8, places: 2)->default(0);
            $table->decimal('lucroVendaRS', total: 8, places: 2)->default(0);
            $table->decimal('lucroVendaPercentual', total: 8, places: 2)->default(0);
            $table->date('dataAdquirida')->nullable();
            $table->date('dataVenda')->nullable();
            $table->date('dataVendida')->nullable();
            $table->string('perfilOrigem');
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venda_chave_trocas');
    }
};
