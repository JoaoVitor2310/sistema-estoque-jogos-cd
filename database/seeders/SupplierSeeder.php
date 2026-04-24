<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupplierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('suppliers')->insertOrIgnore([
            ['supplier_url' => 'https://steamcommunity.com/profiles/76561198028508165'],
            ['supplier_url' => 'https://steamcommunity.com/profiles/76561198257358048'],
        ]);
    }
}
