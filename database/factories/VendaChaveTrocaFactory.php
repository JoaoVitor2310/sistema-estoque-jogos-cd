<?php

namespace Database\Factories;

use App\Domain\Enums\ClaimType;
use App\Domain\Enums\KeyFormat;
use App\Domain\Enums\SellPlatform;
use App\Models\Venda_chave_troca;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Venda_chave_troca>
 */
class VendaChaveTrocaFactory extends Factory
{
    protected $model = Venda_chave_troca::class;

    public function definition(): array
    {
        return [
            'id_fornecedor' => 1,
            'claim_type' => $this->faker->randomElement(ClaimType::cases())->value,
            'steamId' => $this->faker->uuid,
            'key_format' => $this->faker->randomElement(KeyFormat::cases())->value,
            'chaveRecebida' => $this->faker->word,
            'repetido' => $this->faker->boolean,
            'plataformaIdentificada' => $this->faker->randomElement(['Steam', 'Ubisoft', 'EA']),
            'nomeJogo' => $this->faker->word,
            'precoJogo' => $this->faker->randomFloat(2, 0.1, 100),
            'observacao' => $this->faker->sentence,
            'sell_platform' => $this->faker->randomElement(SellPlatform::cases())->value,
            'precoCliente' => $this->faker->randomFloat(2, 0.1, 100),
            'incomeSimulado' => $this->faker->randomFloat(2, 0, 100),
            'valorPagoTotal' => (string) $this->faker->randomFloat(2, 0, 100),
            'qtdTF2' => $this->faker->randomFloat(2, 0.1, 100),
            'valorPagoIndividual' => $this->faker->randomFloat(2, 0, 100),
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
