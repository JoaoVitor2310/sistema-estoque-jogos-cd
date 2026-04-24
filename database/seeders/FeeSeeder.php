<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('fees')->insert([
            ['name' => 'gamivoPercentualMaior', 'preco' => 0.08],
            ['name' => 'gamivoFixoMaior',       'preco' => 0.40],
            ['name' => 'gamivoPercentualMenor', 'preco' => 0.06],
            ['name' => 'gamivoFixoMenor',       'preco' => 0.25],
        ]);
    }
}
