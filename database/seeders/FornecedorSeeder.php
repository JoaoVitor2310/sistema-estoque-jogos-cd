<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FornecedorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('fornecedor')->insert([
            ['perfilOrigem' => 'https://steamcommunity.com/profiles/76561198028508165', 'quantidade_reclamacoes' => 0],
            ['perfilOrigem' => 'https://steamcommunity.com/profiles/76561198257358048', 'quantidade_reclamacoes' => 0],
        ]);
    }
}
