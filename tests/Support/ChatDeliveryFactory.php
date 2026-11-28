<?php

namespace Tests\Support;

use App\Domain\Enums\TradeCurrency;
use App\Models\Trade;
use App\Models\TradeLine;
use App\UseCases\Trades\CreateTradeWithOfferUseCase;
use App\UseCases\Trades\DTO\TradeWithOfferDTO;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Trades com oferta para a entrega pelo chat, criadas pelo caminho de verdade
 * (`CreateTradeWithOfferUseCase`): o supplier com SteamID, o número da trade e
 * a ligação entre cada linha da oferta e a linha da trade.
 *
 * Com a TF2 a €0,95 e o supplier sem categoria, Half-Life (€4,50) sai a 2,46
 * TF2 e Portal (€6,00) a 3,34 — total 5,80.
 */
final class ChatDeliveryFactory
{
    public const STEAM_ID = '76561198000000777';

    public const OTHER_STEAM_ID = '76561198000000888';

    public static function seedDependencies(): void
    {
        Cache::flush();

        DB::table('fees')->insertOrIgnore([
            ['name' => 'gamivo_percent_low', 'preco' => 0.06, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivo_fixed_low', 'preco' => 0.25, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivo_percent_high', 'preco' => 0.08, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gamivo_fixed_high', 'preco' => 0.40, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('assets')->insert([
            'name' => 'TF2', 'price_euro' => 0.95, 'price_dollar' => 1.10, 'price_brl' => 0.0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Trade em negociação com Half-Life e Portal na oferta. */
    public static function trade(string $steamId = self::STEAM_ID): Trade
    {
        return app(CreateTradeWithOfferUseCase::class)->execute(new TradeWithOfferDTO(
            $steamId,
            [
                ['name' => 'Half-Life', 'market_price_euro' => 4.50, 'popularity' => 500, 'region' => null, 'gamivo_id' => null],
                ['name' => 'Portal', 'market_price_euro' => 6.00, 'popularity' => 500, 'region' => null, 'gamivo_id' => null],
            ],
            TradeCurrency::Tf2,
            null,
            null,
        ));
    }

    /**
     * As linhas da trade por nome do jogo.
     *
     * @return array<string, TradeLine>
     */
    public static function lines(Trade $trade): array
    {
        return $trade->lines()->get()->keyBy('game_name')->all();
    }

    /**
     * Pares (linha, key) para todos os jogos da oferta.
     *
     * @return array<int, string>
     */
    public static function allKeys(Trade $trade): array
    {
        $lines = self::lines($trade);

        return [
            $lines['Half-Life']->id => 'AAAAA-BBBBB-CCCCC',
            $lines['Portal']->id => 'DDDDD-EEEEE-FFFFF',
        ];
    }
}
