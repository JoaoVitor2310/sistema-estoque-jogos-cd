<?php

namespace Database\Factories;

use App\Models\Plataforma;
use App\Models\Tipo_formato;
use App\Models\Tipo_leilao;
use App\Models\Tipo_reclamacao;
use App\Models\Venda_chave_troca;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Model>
 */
class VendaChaveTrocaFactory extends Factory
{
    protected $model = Venda_chave_troca::class;
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id_fornecedor' => $this->faker->numberBetween(1, 2), // Ajuste conforme seu seeder de fornecedores
            'tipo_reclamacao_id' => Tipo_reclamacao::inRandomOrder()->first()->id, // Ajuste conforme seu seeder de tipos de reclamação
            'steamId' => $this->faker->uuid,
            'tipo_formato_id' => Tipo_formato::inRandomOrder()->first()->id,
            'chaveRecebida' => $this->faker->word,
            'repetido' => $this->faker->boolean,
            'plataformaIdentificada' => $this->faker->randomElement(['Steam', 'Ubisoft', 'EA']),
            'nomeJogo' => $this->faker->word,
            'precoJogo' => $this->faker->randomFloat(2, 0.1, 100),
            'notaMetacritic' => $this->faker->numberBetween(0, 100),
            'isSteam' => $this->faker->boolean,
            'randomClassificationG2A' => $this->faker->randomElement(['bronze', 'silver', 'gold']),
            'randomClassificationKinguin' => $this->faker->randomElement(['bronze', 'silver', 'gold']),
            'observacao' => $this->faker->sentence,
            'id_leilao_g2a' => Tipo_leilao::inRandomOrder()->first()->id,
            'id_leilao_gamivo' => Tipo_leilao::inRandomOrder()->first()->id,
            'id_leilao_kinguin' => Tipo_leilao::inRandomOrder()->first()->id,
            'id_plataforma' => Plataforma::inRandomOrder()->first()->id,
            'precoCliente' => $this->faker->randomFloat(2, 0, 100),
            'precoVenda' => $this->faker->randomFloat(2, 0, 100),
            'incomeReal' => $this->faker->randomFloat(2, 0, 100),
            'incomeSimulado' => $this->faker->randomFloat(2, 0, 100),
            'chaveEntregue' => $this->faker->word,
            'valorPagoTotal' => $this->faker->randomFloat(2, 0, 100),
            'qtdTF2' => $this->faker->randomFloat(2, 0, 100),
            'valorPagoIndividual' => $this->faker->randomFloat(2, 0, 100),
            'vendido' => $this->faker->boolean,
            'leiloes' => $this->faker->numberBetween(0, 10),
            'quantidade' => $this->faker->numberBetween(1, 100),
            'devolucoes' => $this->faker->boolean,
            'lucroRS' => $this->faker->randomFloat(2, 0, 100),
            'lucroPercentual' => $this->faker->randomFloat(2, 0, 100),
            'valorVendido' => $this->faker->randomFloat(2, 0.1, 100),
            'lucroVendaRS' => $this->faker->randomFloat(2, 0.1, 100),
            'lucroVendaPercentual' => $this->faker->randomFloat(2, 1, 100),
            'dataAdquirida' => $this->faker->date(),
            'dataVenda' => $this->faker->date(),
            'dataVendida' => $this->faker->date(),
            'perfilOrigem' => $this->faker->word,
            'email' => $this->faker->safeEmail,
        ];
    }
}
