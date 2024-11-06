<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlataformaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('plataforma')->insert([
            ['name' => 'Nenhuma'],
            ['name' => 'G2A'],
            ['name' => 'Gamivo'],
            ['name' => 'Kinguin'],
            // ['name' => 'Eneba'],
        ]);
    }
}
