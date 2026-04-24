<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Key;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            SupplierSeeder::class,
            AssetSeeder::class,
            FeeSeeder::class,
            AuthorizedUsersSeeder::class,
            KeySeeder::class,
        ]);

        // Venda_chave_troca::factory(10)->create();
    }
}
