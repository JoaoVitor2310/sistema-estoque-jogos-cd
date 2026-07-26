<?php

namespace App\UseCases\Trades;

use App\Models\Trade;
use App\Services\Suppliers\SupplierService;
use Carbon\Carbon;

class CreateTradeUseCase
{
    public function __construct(
        private readonly SupplierService $supplierService,
    ) {}

    /**
     * @param  array{supplierUrl?: string|null, date?: string|null, tf2Qty?: string|null, games?: array<int, mixed>}  $data
     */
    public function execute(array $data): Trade
    {
        $supplier = ($data['supplierUrl'] ?? null)
            ? $this->supplierService->upsertByUrl($data['supplierUrl'])
            : null;

        return Trade::create([
            'supplier_id' => $supplier?->id,
            'title' => ($data['title'] ?? null) ?: ($supplier?->name ?: null),
            'date' => $this->parseDate($data['date'] ?? null) ?? now()->format('Y-m-d'),
            'tf2_qty' => ($data['tf2Qty'] ?? null) ?: null,
            // Trade nasce com uma linha em branco para o usuário editar direto;
            // sem isso o card apareceria vazio e obrigaria clicar em "+ Linha" antes.
            'games' => $data['games'] ?? [self::emptyRow()],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function emptyRow(): array
    {
        return [
            'name' => '',
            'marketPriceRaw' => '',
            'bundle' => '',
            'expiry' => '',
            'popularity' => '',
            'regionLock' => '',
            'keyCode' => '',
            'gamivoId' => '',
        ];
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
