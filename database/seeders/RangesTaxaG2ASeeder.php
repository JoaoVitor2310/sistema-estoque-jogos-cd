<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RangesTaxaG2ASeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('ranges_taxa_g2a')->insert([
            ['minimo' => 0, 'maximo' => 0.99, 'taxa' => 0.23],
            ['minimo' => 1, 'maximo' => 2.99, 'taxa' => 0.3],
            ['minimo' => 3, 'maximo' => 3.99, 'taxa' => 0.2775],
            ['minimo' => 4, 'maximo' => 6.99, 'taxa' => 0.255],
            ['minimo' => 7, 'maximo' => 7.99, 'taxa' => 0.243],
            ['minimo' => 8, 'maximo' => 8.99, 'taxa' => 0.2315],
            ['minimo' => 9, 'maximo' => 10.49, 'taxa' => 0.2085],
            ['minimo' => 10.5, 'maximo' => 10.99, 'taxa' => 0.197],
            ['minimo' => 11, 'maximo' => 11.99, 'taxa' => 0.185],
            ['minimo' => 12, 'maximo' => 12.99, 'taxa' => 0.174],
            ['minimo' => 13, 'maximo' => 13.99, 'taxa' => 0.162],
            ['minimo' => 14, 'maximo' => 14.99, 'taxa' => 0.156],
            ['minimo' => 15, 'maximo' => 16.99, 'taxa' => 0.145],
            ['minimo' => 17, 'maximo' => 17.49, 'taxa' => 0.139],
            ['minimo' => 17.50, 'maximo' => 19.99, 'taxa' => 0.133],
            ['minimo' => 20, 'maximo' => 21.99, 'taxa' => 0.116],
            ['minimo' => 22, 'maximo' => 22.99, 'taxa' => 0.11],
            ['minimo' => 23, 'maximo' => 23.99, 'taxa' => 0.98],
            ['minimo' => 24, 'maximo' => 25.99, 'taxa' => 0.093],
            ['minimo' => 26, 'maximo' => 49.49, 'taxa' => 0.087],
            ['minimo' => 49.5, 'maximo' => 999, 'taxa' => 0.0405],
        ]);
    }
}
