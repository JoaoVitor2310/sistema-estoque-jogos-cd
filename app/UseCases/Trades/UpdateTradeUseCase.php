<?php

namespace App\UseCases\Trades;

use App\Models\Trade;
use App\Services\Suppliers\SupplierService;
use Carbon\Carbon;

class UpdateTradeUseCase
{
    public function __construct(
        private readonly SupplierService $supplierService,
    ) {}

    /**
     * @param  array{title?: string|null, supplierUrl?: string|null, date?: string|null, tf2Qty?: string|null, games?: array<int, mixed>}  $data
     */
    public function execute(Trade $trade, array $data): void
    {
        $trade->update([
            'title' => $data['title'] ?? null,
            'supplier_id' => $this->supplierService->resolveIdByUrl($data['supplierUrl'] ?? null),
            'date' => $this->parseDate($data['date'] ?? null),
            'tf2_qty' => ($data['tf2Qty'] ?? null) ?: null,
            'games' => $data['games'] ?? [],
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
