<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RecursosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('recursos')->insert([
            ['name' => 'TF2', 'preco_euro' => 1.37, 'preco_dolar' => 8.23, 'preco_real' => 8.24],
            ['name' => 'Gema', 'preco_euro' => 1.37, 'preco_dolar' => 8.23, 'preco_real' => 8.24],
            ['name' => 'ToD', 'preco_euro' => 1.37, 'preco_dolar' => 8.23, 'preco_real' => 8.24],
        ]);
    }
}
