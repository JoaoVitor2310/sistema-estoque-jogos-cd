<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class fillMinMaxApiGamivo extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtém todas as keys
        $keys = DB::table('venda_chave_trocas')->get();

        foreach ($keys as $key) {
            // Aplica a lógica para minApiGamivo
            if ($key->valorPagoIndividual < 4) {
                $minApiGamivo = $key->valorPagoIndividual * 1.6;
            } elseif ($key->valorPagoIndividual > 10) {
                $minApiGamivo = $key->valorPagoIndividual * 1.4;
            } elseif ($key->valorPagoIndividual > 4.6) {
                $minApiGamivo = $key->valorPagoIndividual * 1.5;
            } else {
                $minApiGamivo = $key->valorPagoIndividual; // Caso não se encaixe em nenhuma regra
            }

            // Aplica a lógica para maxApiGamivo
            $maxApiGamivo = $key->valorPagoIndividual * 8;

            // Atualiza a linha no banco de dados
            DB::table('venda_chave_trocas')
                ->where('id', $key->id)
                ->update([
                    'minApiGamivo' => $minApiGamivo,
                    'maxApiGamivo' => $maxApiGamivo,
                ]);
        }
    }
}
