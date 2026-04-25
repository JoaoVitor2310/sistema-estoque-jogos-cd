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
            ['name' => 'gamivo_percent_high', 'preco' => 0.08],
            ['name' => 'gamivo_fixed_high',   'preco' => 0.40],
            ['name' => 'gamivo_percent_low',  'preco' => 0.06],
            ['name' => 'gamivo_fixed_low',    'preco' => 0.25],
        ]);
    }
}
