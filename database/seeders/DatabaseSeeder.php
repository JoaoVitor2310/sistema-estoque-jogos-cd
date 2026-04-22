<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Venda_chave_troca;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            FornecedorSeeder::class,
            RangesTaxaG2ASeeder::class,
            RecursosSeeder::class,
            TaxasSeeder::class,
            AuthorizedUsersSeeder::class,
            VendaChaveTrocaSeeder::class,
        ]);

        // Venda_chave_troca::factory(10)->create();
    }
}
