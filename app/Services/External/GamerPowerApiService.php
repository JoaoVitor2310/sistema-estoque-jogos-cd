<?php

namespace App\Services\External;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP da API do GamerPower (giveaways ativos).
 * Infraestrutura pura — traduz o formato da API, sem regra de negócio.
 *
 * Contrato e campos da resposta: docs/GAMERPOWER.md.
 */
class GamerPowerApiService
{
    /**
     * Só jogo completo com key Steam: DLC, loot, beta e as outras lojas ficam
     * de fora já na consulta.
     */
    public const PLATFORM = 'steam';

    public const TYPE = 'game';

    public const TIMEOUT_SECONDS = 30;

    /** A API responde 201, e não 200, quando não há nenhum giveaway ativo. */
    private const NO_ACTIVE_GIVEAWAYS_STATUS = 201;

    /**
     * Giveaways de jogo Steam ativos agora.
     *
     * `[]` e `null` não são a mesma coisa: `[]` é a API dizendo que não há
     * nenhum ativo (o que encerra os que estavam), `null` é a consulta que
     * falhou — e falha não pode encerrar giveaway nenhum.
     *
     * @return list<array{gamerpower_id: int, title: string, worth: ?float, gamerpower_url: string, published_date: ?Carbon, end_date: ?Carbon}>|null
     */
    public function activeSteamGames(): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->get(config('services.gamerpower.base_url').'/giveaways', [
                    'platform' => self::PLATFORM,
                    'type' => self::TYPE,
                ]);
        } catch (ConnectionException $e) {
            Log::error('GamerPower API unreachable: '.$e->getMessage());

            return null;
        }

        if ($response->status() === self::NO_ACTIVE_GIVEAWAYS_STATUS) {
            return [];
        }

        $items = $response->json();

        if (! $response->ok() || ! is_array($items) || ! array_is_list($items)) {
            Log::error('GamerPower API returned an unexpected response: HTTP '.$response->status());

            return null;
        }

        $giveaways = [];

        foreach ($items as $item) {
            if (! isset($item['id'], $item['title'])) {
                continue;
            }

            $giveaways[] = [
                'gamerpower_id' => (int) $item['id'],
                'title' => (string) $item['title'],
                'worth' => self::money($item['worth'] ?? null),
                'gamerpower_url' => (string) ($item['gamerpower_url'] ?? ''),
                'published_date' => self::date($item['published_date'] ?? null),
                'end_date' => self::date($item['end_date'] ?? null),
            ];
        }

        return $giveaways;
    }

    /** "$9.99" → 9.99; "N/A" → null. */
    private static function money(mixed $value): ?float
    {
        $number = preg_replace('/[^0-9.]/', '', (string) $value);

        return is_numeric($number) ? (float) $number : null;
    }

    /** "2026-10-31 23:59:59" → Carbon; "N/A" ou vazio → null. */
    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
