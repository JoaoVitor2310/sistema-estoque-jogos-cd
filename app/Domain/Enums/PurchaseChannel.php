<?php

namespace App\Domain\Enums;

/**
 * De quem compramos as keys de uma trade.
 *
 * É um fato do lote inteiro, não de cada key: todas as keys de uma compra
 * vieram do mesmo lugar. Não confundir com `trade_lines.bundle`, que diz de
 * que bundle a key **saiu** (pista do region lock) — uma key comprada de um
 * supplier também pode ter saído de um bundle.
 *
 * Cada canal diz qual contraparte a trade precisa ter para ser importada. A
 * contraparte que o canal não usa é descartada na escrita
 * ([[App\UseCases\Trades\UpdateTradeUseCase]]), para uma trade nunca carregar
 * ao mesmo tempo um fornecedor e um bundle.
 */
enum PurchaseChannel: string
{
    /** Origem gravada nas keys compradas na Gamivo — não há URL nem nome de contraparte. */
    public const GAMIVO_SOURCE = 'Gamivo';

    /** Origem de reserva para compra direta cujo bundle não tem nome cadastrado. */
    public const UNNAMED_BUNDLE_SOURCE = 'Bundle';

    /** Trade com um fornecedor da Steam — o caso comum. */
    case SupplierTrade = 'supplier_trade';

    /** Compra direta na loja do bundle (Humble, Fanatical, Green Man Gaming…). */
    case BundleStore = 'bundle_store';

    /** Compra no próprio marketplace da Gamivo. */
    case Gamivo = 'gamivo';

    /** Se a trade só é importável com um fornecedor vinculado. */
    public function requiresSupplier(): bool
    {
        return $this === self::SupplierTrade;
    }

    /** Se a trade só é importável com o bundle da compra vinculado. */
    public function requiresBundle(): bool
    {
        return $this === self::BundleStore;
    }

    /**
     * De onde a key veio, como texto gravado em `keys.supplier_url`.
     *
     * A coluna nunca fica vazia: para trade com supplier é a URL do perfil;
     * para compra direta, o nome do bundle; para a Gamivo, o próprio nome. É o
     * que permite ler e filtrar a origem na tela de Keys em qualquer canal. O
     * vínculo de verdade continua sendo `keys.supplier_id`/`trades.bundle_id` —
     * este texto é só a leitura humana dele.
     */
    public function keySource(?string $supplierUrl, ?string $bundleName): string
    {
        return match ($this) {
            self::SupplierTrade => (string) $supplierUrl,
            self::BundleStore => trim((string) $bundleName) ?: self::UNNAMED_BUNDLE_SOURCE,
            self::Gamivo => self::GAMIVO_SOURCE,
        };
    }
}
