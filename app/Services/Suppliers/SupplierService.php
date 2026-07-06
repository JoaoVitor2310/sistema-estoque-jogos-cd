<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;

class SupplierService
{
    public function findOrCreate(string $supplierUrl): int
    {
        return Supplier::firstOrCreate(
            ['url' => $this->normalizeUrl($supplierUrl)],
            ['has_traded' => true],
        )->id;
    }

    /**
     * @param  array{steam_id: string, url: string}  $data
     */
    public function upsert(array $data): Supplier
    {
        return Supplier::updateOrCreate(
            ['steam_id' => $data['steam_id']],
            ['url' => $this->normalizeUrl($data['url'])],
        );
    }

    public function upsertByUrl(string $url): Supplier
    {
        return Supplier::firstOrCreate(['url' => $this->normalizeUrl($url)]);
    }

    public function resolveIdByUrl(?string $url): ?int
    {
        if (! $url) {
            return null;
        }

        return $this->upsertByUrl($url)->id;
    }

    public function resolveBySteamId(?string $steamId): ?Supplier
    {
        if (! $steamId) {
            return null;
        }

        return $this->upsert([
            'steam_id' => $steamId,
            'url' => 'https://steamcommunity.com/profiles/'.$steamId,
        ]);
    }

    private function normalizeUrl(string $url): string
    {
        return rtrim($url, '/');
    }
}
