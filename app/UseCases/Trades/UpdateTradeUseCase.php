<?php

namespace App\UseCases\Trades;

use App\Domain\Enums\PurchaseChannel;
use App\Models\Trade;
use App\Services\Bundles\BundleService;
use App\Services\Suppliers\SupplierService;
use Carbon\Carbon;

class UpdateTradeUseCase
{
    public function __construct(
        private readonly SupplierService $supplierService,
        private readonly BundleService $bundleService,
    ) {}

    /**
     * Atualiza só os campos da própria trade. As linhas têm rotas próprias —
     * uma gravação da trade não pode mais substituir o conjunto inteiro delas.
     *
     * O canal de compra decide qual contraparte a trade guarda: a que o canal não
     * usa é descartada, mesmo que o payload a traga. Assim uma trade nunca fica
     * com fornecedor **e** bundle — e trocar o canal por engano não cria um
     * `Supplier` a partir do que estava no campo.
     *
     * Na compra direta o bundle não é escolhido: sai do título, que na trade
     * criada pela pesquisa de bundle já é o nome dele. Título que não casa com
     * bundle nenhum deixa `bundle_id` nulo, e o import recusa a trade por isso —
     * a falha aparece, não passa calada.
     *
     * @param  array{title?: string|null, purchaseChannel?: string|null, supplierUrl?: string|null, date?: string|null, tf2Qty?: string|null, message_sent?: bool}  $data
     */
    public function execute(Trade $trade, array $data): void
    {
        $channel = PurchaseChannel::tryFrom($data['purchaseChannel'] ?? '') ?? PurchaseChannel::SupplierTrade;

        $trade->update([
            'title' => $data['title'] ?? null,
            'purchase_channel' => $channel,
            'supplier_id' => $channel->requiresSupplier()
                ? $this->supplierService->resolveIdByUrl($data['supplierUrl'] ?? null)
                : null,
            'bundle_id' => $channel->requiresBundle()
                ? $this->bundleService->findIdByName($data['title'] ?? null)
                : null,
            'date' => $this->parseDate($data['date'] ?? null),
            'tf2_qty' => ($data['tf2Qty'] ?? null) ?: null,
            'message_sent' => (bool) ($data['message_sent'] ?? false),
        ]);
    }

    private function parseDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }

        try {
            return Carbon::createFromFormat('d/m/Y', $date)->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}
