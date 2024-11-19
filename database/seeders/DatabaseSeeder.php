<?php

namespace Database\Seeders;

use App\Models\User;
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
            PlataformaSeeder::class,
            RangesTaxaG2ASeeder::class,
            RecursosSeeder::class,
            TaxasSeeder::class,
            TipoFormatoSeeder::class,
            TipoLeilaoSeeder::class,
            TipoReclamacaoSeeder::class,
            AuthorizedUsersSeeder::class,
            VendaChaveTrocaSeeder::class,
        ]);

        // Venda_chave_troca::factory(10)->create();
    }
}
