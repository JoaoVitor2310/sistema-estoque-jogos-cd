<?php

namespace App\Domain\Enums;

/**
 * O que impede uma trade de virar keys.
 *
 * Produzido por [[App\Domain\Trades\ImportReadinessPolicy]]. Cada caso é uma
 * condição da trade inteira, não de uma linha: o import é atômico, então saber
 * *qual* linha está sem `key_code` não muda o desfecho — o lote não entra.
 */
enum TradeImportBlocker: string
{
    /** Nenhuma linha preenchida — trade só com rascunho em branco. */
    case NoFilledLine = 'no_filled_line';

    case MissingGameName = 'missing_game_name';

    case MissingMarketPrice = 'missing_market_price';

    case MissingKeyCode = 'missing_key_code';

    /** Sem quantidade de TF2 o rateio de `individual_cost` rodaria sem custo. */
    case MissingTf2Quantity = 'missing_tf2_quantity';

    /** Trade com fornecedor (ver [[PurchaseChannel::SupplierTrade]]) sem fornecedor vinculado. */
    case MissingSupplierUrl = 'missing_supplier_url';

    /** Compra direta (ver [[PurchaseChannel::BundleStore]]) sem o bundle da compra. */
    case MissingBundle = 'missing_bundle';
}
