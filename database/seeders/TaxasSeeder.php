<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
            ['name' => 'gamivoPercentualMaior4', 'preco' => 0.079],
            ['name' => 'gamivoFixoMaior4', 'preco' => 0.35],
            
            ['name' => 'gamivoPercentualMenor4', 'preco' => 0.05],
            ['name' => 'gamivoFixoMenor4', 'preco' => 0.1],
            
        ]);
    }
}
