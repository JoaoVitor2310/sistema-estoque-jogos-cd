<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TaxasSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('taxas')->insert([
            ['name' => 'gamivoPercentualMaior', 'preco' => 0.08],
            ['name' => 'gamivoFixoMaior', 'preco' => 0.40],

            ['name' => 'gamivoPercentualMenor', 'preco' => 0.06],
            ['name' => 'gamivoFixoMenor', 'preco' => 0.25],

        ]);
    }
}
