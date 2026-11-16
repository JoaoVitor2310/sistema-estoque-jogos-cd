<?php

namespace App\UseCases\Trades;

use App\Domain\Enums\PurchaseChannel;
use App\Domain\Enums\TradeNumberChange;
use App\Models\Trade;
use App\Services\Bundles\BundleService;
use App\Services\Suppliers\SupplierService;
use App\Services\Trades\SupplierTradeNumbering;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class UpdateTradeUseCase
{
    public function __construct(
        private readonly SupplierService $supplierService,
        private readonly BundleService $bundleService,
        private readonly SupplierTradeNumbering $numbering,
    ) {}

    /**
     * Atualiza só os campos da própria trade. As linhas têm rotas próprias —
     * uma gravação da trade não pode mais substituir o conjunto inteiro delas.
     * A moeda também não passa por aqui: trocá-la converte a quantidade, e é
     * [[ConvertTradeCurrencyUseCase]] quem faz isso.
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
     * Trocar o supplier troca o número da trade: ela recebe o próximo do
     * supplier novo, e sem supplier o número fica nulo. O número antigo não
     * volta para o supplier antigo — o contador dele só sobe.
     *
     * @param  array{title?: string|null, purchaseChannel?: string|null, supplierUrl?: string|null, date?: string|null, amount?: string|null, message_sent?: bool}  $data
     */
    public function execute(Trade $trade, array $data): void
    {
        $channel = PurchaseChannel::tryFrom($data['purchaseChannel'] ?? '') ?? PurchaseChannel::SupplierTrade;

        $supplierId = $channel->requiresSupplier()
            ? $this->supplierService->resolveIdByUrl($data['supplierUrl'] ?? null)
            : null;

        DB::transaction(function () use ($trade, $data, $channel, $supplierId) {
            $trade->fill([
                'title' => $data['title'] ?? null,
                'purchase_channel' => $channel,
                'supplier_id' => $supplierId,
                'bundle_id' => $channel->requiresBundle()
                    ? $this->bundleService->findIdByName($data['title'] ?? null)
                    : null,
                'date' => $this->parseDate($data['date'] ?? null),
                'amount' => ($data['amount'] ?? null) ?: null,
                'message_sent' => (bool) ($data['message_sent'] ?? false),
            ]);

            // Supplier e número no mesmo `save`: gravar o supplier novo com o
            // número antigo bateria no índice único se o novo já tiver uma trade
            // com esse número. O número não é fillable, daí o `forceFill`.
            $previousSupplierId = $trade->getOriginal('supplier_id');

            match (TradeNumberChange::onSupplierChange($previousSupplierId === null ? null : (int) $previousSupplierId, $supplierId)) {
                TradeNumberChange::Keep => null,
                TradeNumberChange::Drop => $trade->forceFill([SupplierTradeNumbering::COLUMN => null]),
                TradeNumberChange::NextOfNewSupplier => $trade->forceFill($this->numbering->for($supplierId)),
            };

            $trade->save();
        });
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
