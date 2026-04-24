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
            'key_code' => $this->faker->word,
            'is_duplicate' => $this->faker->boolean,
            'identified_platform' => $this->faker->randomElement(['Steam', 'Ubisoft', 'EA']),
            'game_name' => $this->faker->word,
            'precoJogo' => $this->faker->randomFloat(2, 0.1, 100),
            'observacao' => $this->faker->sentence,
            'sell_platform' => $this->faker->randomElement(SellPlatform::cases())->value,
            'market_price' => $this->faker->randomFloat(2, 0.1, 100),
            'simulated_income' => $this->faker->randomFloat(2, 0, 100),
            'total_paid' => (string) $this->faker->randomFloat(2, 0, 100),
            'tf2_quantity' => $this->faker->randomFloat(2, 0.1, 100),
            'individual_cost' => $this->faker->randomFloat(2, 0, 100),
            'purchase_profit' => $this->faker->randomFloat(2, 0, 100),
            'purchase_profit_percent' => $this->faker->randomFloat(2, 0, 100),
            'sold_price' => $this->faker->randomFloat(2, 0.1, 100),
            'sale_profit' => $this->faker->randomFloat(2, 0.1, 100),
            'sale_profit_percent' => $this->faker->randomFloat(2, 1, 100),
            'acquired_at' => $this->faker->date(),
            'listed_at' => $this->faker->date(),
            'sold_at' => $this->faker->date(),
            'supplier_url' => $this->faker->word,
            'email' => $this->faker->safeEmail,
        ];
    }
}
