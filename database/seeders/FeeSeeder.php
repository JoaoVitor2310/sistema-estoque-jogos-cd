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
        // upsert() garante idempotência: rodar o seeder duas vezes não gera duplicatas.
        // O UNIQUE em fees.name (migration 000014) reforça isso no banco.
        DB::table('fees')->upsert(
            [
                ['name' => 'gamivo_percent_high', 'preco' => 0.08],
                ['name' => 'gamivo_fixed_high',   'preco' => 0.40],
                ['name' => 'gamivo_percent_low',  'preco' => 0.06],
                ['name' => 'gamivo_fixed_low',    'preco' => 0.25],
            ],
            uniqueBy: ['name'],
            update:   ['preco'],
        );
    }
}
