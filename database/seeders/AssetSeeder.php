<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AssetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('assets')->insertOrIgnore([
            ['name' => 'TF2', 'price_euro' => 2.0, 'price_dollar' => 2.2, 'price_brl' => 10.0],
        ]);
    }
}
