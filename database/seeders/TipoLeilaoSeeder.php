<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TipoLeilaoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('tipo_leilao')->insert([
            ['name' => 'Provavel'],
            ['name' => 'Confirmado'],
            ['name' => 'Não existe'],
            ['name' => 'Aguardando'],
        ]);
    }
}
