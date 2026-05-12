<?php

namespace App\Services\External;

use Illuminate\Support\Facades\Http;

/**
 * Scraping de popularidade do SteamCharts.
 * Infraestrutura pura — sem regras de negócio.
 *
 * A página de um jogo exibe vários <span class="num">. O segundo span
 * corresponde ao pico de jogadores simultâneos nas últimas 24 horas.
 */
class SteamChartsService
{
    /**
     * Retorna o pico de jogadores simultâneos nas últimas 24h para um jogo.
     * Retorna null em caso de erro, timeout ou resposta inesperada do SteamCharts —
     * diferenciando falha de um jogo com popularidade genuinamente zero.
     */
    public function getPopularity(string $steamId): ?int
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; popularity-bot/1.0)'])
                ->get("https://steamcharts.com/app/{$steamId}");

            if ($response->failed()) {
                return null;
            }

            return $this->parsePopularity($response->body());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extrai o pico 24h do HTML da página.
     * Busca todos os <span class="num"> e retorna o valor do segundo elemento.
     */
    private function parsePopularity(string $html): int
    {
        // Corresponde a <span class="num">1,234</span> (com possíveis separadores de milhar)
        preg_match_all('/<span[^>]+class="num"[^>]*>([\d,]+)<\/span>/i', $html, $matches);

        if (count($matches[1]) < 2) {
            return 0;
        }

        // Remove separador de milhar (vírgula) e converte para inteiro
        return (int) str_replace(',', '', $matches[1][1]);
    }
}
