<?php

namespace App\UseCases\Trades;

use App\Domain\Trades\DeliveryCredential;
use App\Models\Trade;
use App\Services\Suppliers\SupplierService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CreateTradeUseCase
{
    public function __construct(
        private readonly SupplierService $supplierService,
    ) {}

    /**
     * @param  array{title?: string|null, supplierUrl?: string|null, date?: string|null, tf2Qty?: string|null}  $data
     */
    public function execute(array $data): Trade
    {
        $supplier = ($data['supplierUrl'] ?? null)
            ? $this->supplierService->upsertByUrl($data['supplierUrl'])
            : null;

        return DB::transaction(function () use ($data, $supplier) {
            $trade = Trade::create([
                'supplier_id' => $supplier?->id,
                'title' => ($data['title'] ?? null) ?: ($supplier?->name ?: null),
                'date' => $this->parseDate($data['date'] ?? null) ?? now()->format('Y-m-d'),
                'tf2_qty' => ($data['tf2Qty'] ?? null) ?: null,
            ]);

            // Trade nasce com uma linha em branco para o usuário editar direto;
            // sem ela, o primeiro ato sobre a trade seria criar a linha.
            $trade->lines()->create(['position' => 0]);

            // E nasce com a credencial de entrega: o link e o código ficam à
            // vista na aba, prontos para copiar (ver docs/adr/0008). Fora do
            // `create` porque as colunas não são fillable de propósito — nada
            // vindo de payload pode alcançá-las.
            $trade->forceFill(DeliveryCredential::issue())->save();

            return $trade;
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
