<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FillMinMaxApiSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $keys = DB::table('keys')->get();

        foreach ($keys as $key) {
            // Calcula min_api com base no custo individual
            if ($key->valorPagoIndividual < 4) {
                $minApi = $key->valorPagoIndividual * 1.6;
            } elseif ($key->valorPagoIndividual > 10) {
                $minApi = $key->valorPagoIndividual * 1.4;
            } elseif ($key->valorPagoIndividual > 4.6) {
                $minApi = $key->valorPagoIndividual * 1.5;
            } else {
                $minApi = $key->valorPagoIndividual;
            }

            $maxApi = $key->valorPagoIndividual * 8;

            DB::table('keys')
                ->where('id', $key->id)
                ->update([
                    'min_api' => $minApi,
                    'max_api' => $maxApi,
                ]);
        }
    }
}
