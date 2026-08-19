<?php

namespace Tests\Support;

use App\Domain\Trades\DeliveryCredential;
use App\Models\Trade;
use App\UseCases\Trades\DTO\DeliveryTradeDTO;
use Illuminate\Session\Store;

/**
 * Seeds e colaboradores da entrega pelo supplier.
 *
 * Namespaced pelo mesmo motivo de [[TradeFactory]]: helper solto no topo de um
 * arquivo de teste é promovido ao namespace global pelo Pest e colide.
 */
final class DeliveryFactory
{
    /** @param  array<string, mixed>  $payload */
    public static function dto(array $payload = []): DeliveryTradeDTO
    {
        return DeliveryTradeDTO::fromValidated($payload);
    }

    /**
     * Trade com a credencial que ela teria ao nascer; devolve a trade e o token.
     *
     * A factory insere por query builder e não passa pelos UseCases de criação,
     * que são quem grava a credencial.
     *
     * @return array{0: Trade, 1: string}
     */
    public static function tradeWithCredential(): array
    {
        $trade = TradeFactory::withLines(['Portal']);
        $credential = DeliveryCredential::issue();
        $trade->forceFill($credential)->save();

        return [$trade->fresh(), $credential['delivery_token']];
    }

    /** `session()` devolve o manager; o UseCase recebe o Store, como no request. */
    public static function session(): Store
    {
        return app('session.store');
    }
}
