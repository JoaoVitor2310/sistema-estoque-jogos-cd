<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add new string columns alongside FK columns
        Schema::table('venda_chave_trocas', function (Blueprint $table) {
            $table->string('tipo_formato')->nullable()->after('tipo_formato_id');
            $table->string('tipo_reclamacao')->nullable()->after('tipo_reclamacao_id');
            $table->string('plataforma_venda')->nullable()->after('id_plataforma');
        });

        // Migrate data from lookup tables to string values (PostgreSQL correlated subquery)
        DB::statement('UPDATE venda_chave_trocas SET tipo_formato = (SELECT name FROM tipo_formato WHERE id = venda_chave_trocas.tipo_formato_id)');
        DB::statement('UPDATE venda_chave_trocas SET tipo_reclamacao = (SELECT name FROM tipo_reclamacao WHERE id = venda_chave_trocas.tipo_reclamacao_id)');
        DB::statement('UPDATE venda_chave_trocas SET plataforma_venda = (SELECT name FROM plataforma WHERE id = venda_chave_trocas.id_plataforma)');

        // Drop FK constraints and old FK columns
        Schema::table('venda_chave_trocas', function (Blueprint $table) {
            $table->dropForeign(['tipo_formato_id']);
            $table->dropForeign(['tipo_reclamacao_id']);
            $table->dropForeign(['id_plataforma']);
            $table->dropColumn(['tipo_formato_id', 'tipo_reclamacao_id', 'id_plataforma']);
        });

        // Drop the now-unused lookup tables and tipo_leilao
        Schema::dropIfExists('tipo_leilao');
        Schema::dropIfExists('tipo_formato');
        Schema::dropIfExists('tipo_reclamacao');
        Schema::dropIfExists('plataforma');
    }

    public function down(): void
    {
        Schema::create('tipo_leilao', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('tipo_formato', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('tipo_reclamacao', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('plataforma', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('venda_chave_trocas', function (Blueprint $table) {
            $table->integer('tipo_formato_id')->default(1);
            $table->integer('tipo_reclamacao_id')->default(1);
            $table->integer('id_plataforma')->default(1);
            $table->dropColumn(['tipo_formato', 'tipo_reclamacao', 'plataforma_venda']);
        });
    }
};
